#!/usr/bin/env python3
"""Transparent STDIO test proxy. Persist metadata, never arguments or responses."""
import argparse
import hashlib
import json
import math
import os
import re
from pathlib import Path
import selectors
import subprocess
import sys
import time
import uuid

FIELDS = {'time', 'event', 'instance_id', 'pid', 'method', 'tool', 'action', 'request_id', 'root', 'ok', 'status', 'fingerprint', 'source_hash', 'begin_hash', 'witnesses', 'candidate_count', 'exit_code', 'bytes', 'error_kind'}
EVENTS = {'started', 'request', 'response', 'exited', 'malformed', 'queued', 'barrier_timeout'}
TOOLS = {'repository-context', 'architecture-search', 'search', 'task-control', 'enabled-architectures', 'file-rules', 'analysis-consent', 'architecture-map'}
ACTIONS = {'bind', 'status', 'begin', 'check', 'prepare', 'result', 'grant'}
STATUSES = {'bound', 'unbound', 'unverified', 'pass', 'started', 'stale', 'missing', 'blocked', 'skipped', 'ready', 'building', 'current', 'found', 'no_matches', 'incomplete', 'limit', 'context_required'}
METHODS = {'initialize', 'notifications/initialized', 'tools/list', 'tools/call', 'resources/list', 'resources/read', 'ping'}


def checked_event(event):
    if set(event) - FIELDS or event.get('event') not in EVENTS:
        raise ValueError('Metadata allowlist violation')
    for field in ('fingerprint', 'source_hash', 'begin_hash'):
        if field in event and (not isinstance(event[field], str) or len(event[field]) != 64 or any(c not in '0123456789abcdef' for c in event[field])):
            raise ValueError('Invalid metadata hash')
    for field, allowed in [('method', METHODS | {'other'}), ('tool', TOOLS | {'other'}), ('action', ACTIONS | {'other'}), ('status', STATUSES | {'other'}), ('error_kind', {'context_required','context_conflict','context_invalid','stale_snapshot','other'})]:
        if field in event and event[field] not in allowed:
            raise ValueError('Invalid metadata enum')
    if 'root' in event and event['root'] not in {'main.crm-backend', 'main.glenross', 'a.crm-backend', 'a.glenross', 'b.crm-backend', 'b.glenross', 'unbound', 'other'}:
        raise ValueError('Invalid root alias')
    for field in ('pid', 'exit_code', 'bytes', 'candidate_count'):
        if field in event and type(event[field]) is not int:
            raise ValueError('Invalid numeric metadata')
    if 'ok' in event and type(event['ok']) is not bool:
        raise ValueError('Invalid boolean metadata')
    if 'time' in event and (type(event['time']) not in (int, float) or not math.isfinite(event['time'])):
        raise ValueError('Invalid timestamp')
    if 'witnesses' in event and (not isinstance(event['witnesses'], list) or any(w not in {'onlyA','onlyB','onlyMain','ParentOnly'} for w in event['witnesses'])):
        raise ValueError('Invalid witness labels')
    for field, size in [('instance_id',32), ('request_id',64)]:
        if field in event and (not isinstance(event[field],str) or len(event[field]) != size or any(c not in '0123456789abcdef' for c in event[field])):
            raise ValueError('Invalid identifier')
    return event


def structured(response):
    result = response.get('result', {})
    if not isinstance(result, dict):
        return {}
    data = result.get('structuredContent')
    if isinstance(data, dict):
        return data
    for row in result.get('content', []):
        if row.get('type') == 'text':
            try:
                data = json.loads(row['text'])
                if isinstance(data, dict):
                    return data
            except (ValueError, TypeError):
                pass
    return {}


def run(args):
    instance = uuid.uuid4().hex
    directory = Path(args.log_dir)
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    log = os.open(directory / (instance + '.jsonl'), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    child = subprocess.Popen(args.command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=None)
    bound = 'unbound'
    pending = {}

    def emit(event, **fields):
        row = checked_event({'time': time.time(), 'event': event, 'instance_id': instance, 'pid': child.pid, **fields})
        os.write(log, (json.dumps(row, separators=(',', ':')) + '\n').encode())

    def alias(path):
        try:
            manifest = json.loads(Path(args.manifest).read_text())
            for group, roots in manifest['roots'].items():
                for family, root in roots.items():
                    if path == root:
                        return group + '.' + family
        except (OSError, ValueError, KeyError):
            pass
        return 'other'

    def barrier(tool):
        # Optional synchronization changes only when genuine requests enter the
        # product, never their payloads, responses or the agent's tool decisions.
        if not args.barrier or tool != 'architecture-search':
            return
        path = Path(args.barrier)
        try:
            spec = json.loads(path.read_text())
        except (OSError, ValueError):
            return
        if not isinstance(spec, dict) or not isinstance(spec.get('roots', []), list):
            return
        token = spec.get('id', '')
        if not isinstance(token, str) or not re.fullmatch(r'[a-zA-Z0-9_-]{1,60}', token) or bound not in spec.get('roots', []):
            return
        ready = path.parent / (token + '.' + bound + '.ready')
        ready.write_text(json.dumps({'instance_id': instance, 'pid': child.pid}))
        emit('queued', root=bound, tool=tool)
        deadline = time.monotonic() + 60
        while not (path.parent / (token + '.go')).is_file():
            if time.monotonic() >= deadline:
                emit('barrier_timeout', root=bound)
                break
            time.sleep(0.005)

    emit('started')
    selector = selectors.DefaultSelector()
    selector.register(sys.stdin.buffer, selectors.EVENT_READ, 'in')
    selector.register(child.stdout, selectors.EVENT_READ, 'out')
    buffers = {'in': b'', 'out': b''}
    try:
        while selector.get_map():
            for key, _ in selector.select(timeout=1):
                direction = key.data
                block = os.read(key.fileobj.fileno(), 65536)
                if not block:
                    selector.unregister(key.fileobj)
                    if direction == 'in':
                        if buffers['in']:
                            os.write(child.stdin.fileno(), buffers['in'])
                            buffers['in'] = b''
                        child.stdin.close()
                    else:
                        if not child.stdin.closed:
                            child.stdin.close()
                        for registered in list(selector.get_map().values()):
                            selector.unregister(registered.fileobj)
                    continue
                # Relay original bytes, including SDK errors and notifications.
                if direction == 'out':
                    view = memoryview(block)
                    while view:
                        written = os.write(sys.stdout.fileno(), view)
                        view = view[written:]
                buffers[direction] += block
                if len(buffers[direction]) > 32000000:
                    emit('malformed', bytes=len(buffers[direction]))
                    if direction == 'in':
                        view = memoryview(buffers[direction])
                        while view:
                            written = os.write(child.stdin.fileno(), view)
                            view = view[written:]
                    buffers[direction] = b''
                while b'\n' in buffers[direction]:
                    line, buffers[direction] = buffers[direction].split(b'\n', 1)
                    try:
                        data = json.loads(line)
                        token = hashlib.sha256(str(data.get('id')).encode()).hexdigest()
                        if direction == 'in':
                            method = data.get('method', 'other')
                            method = method if method in METHODS else 'other'
                            params = data.get('params', {})
                            name = params.get('name', 'other')
                            tool = name if name in TOOLS else 'other'
                            action = params.get('arguments', {}).get('action', 'other')
                            action = action if action in ACTIONS else 'other'
                            pending[token] = {'method': method, 'tool': tool, 'action': action}
                            barrier(tool)
                            emit('request', request_id=token, root=bound, **pending[token])
                        else:
                            content = structured(data)
                            if isinstance(content.get('code_root'), str):
                                bound = alias(content['code_root'])
                            fields = {'request_id': token, 'root': bound, **pending.pop(token, {})}
                            if isinstance(content.get('ok'), bool):
                                fields['ok'] = content['ok']
                            reason = content.get('reason', '')
                            if isinstance(reason, str):
                                for code in ('context_required', 'context_conflict', 'context_invalid', 'stale_snapshot'):
                                    if reason.startswith(code):
                                        fields['error_kind'] = code
                                        break
                            status = content.get('status')
                            if isinstance(status, str):
                                fields['status'] = status if status in STATUSES else 'other'
                            for field in ('fingerprint', 'source_hash'):
                                value = content.get(field)
                                if isinstance(value, str) and len(value) == 64 and all(c in '0123456789abcdef' for c in value):
                                    fields[field] = value
                            beginnings = {k: v for k, v in content.items() if k.startswith('begin_')}
                            if beginnings:
                                fields['begin_hash'] = hashlib.sha256(json.dumps(beginnings, sort_keys=True).encode()).hexdigest()
                            search = content.get('result', content)
                            candidates = search.get('candidates') if isinstance(search, dict) else None
                            if isinstance(candidates, list):
                                fields['candidate_count'] = len(candidates)
                                labels = []
                                for candidate in candidates:
                                    values = [candidate.get(field, '') for field in ('selector', 'id', 'name')] if isinstance(candidate, dict) else []
                                    for witness in ('onlyMain', 'onlyA', 'onlyB', 'ParentOnly'):
                                        if any(isinstance(value, str) and witness in value for value in values):
                                            labels.append(witness)
                                fields['witnesses'] = sorted(set(labels))
                            emit('response', **fields)
                    except (ValueError, TypeError, AttributeError):
                        emit('malformed', bytes=len(line))
                    if direction == 'in':
                        view = memoryview(line + b'\n')
                        while view:
                            written = os.write(child.stdin.fileno(), view)
                            view = view[written:]
        code = child.wait(timeout=15)
        emit('exited', exit_code=code)
        return code
    finally:
        selector.close()
        os.close(log)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--log-dir', required=True)
    parser.add_argument('--manifest', required=True)
    parser.add_argument('--barrier', help='Optional private QA synchronization file; no effect when absent')
    parser.add_argument('command', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    if args.command and args.command[0] == '--':
        args.command.pop(0)
    if not args.command:
        parser.error('A real product command is required')
    return run(args)


if __name__ == '__main__':
    raise SystemExit(main())
