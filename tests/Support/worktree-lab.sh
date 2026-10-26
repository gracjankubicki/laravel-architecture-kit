#!/usr/bin/env bash
# Main-only fixture lifecycle. Never invoked automatically by PHPUnit or install.
set -euo pipefail
python3 - "$@" <<'PY'
import hashlib,json,os,pathlib,shutil,subprocess,sys
command, location = sys.argv[1:3]
root=pathlib.Path(location).absolute()
expected=pathlib.Path(__file__).absolute() if False else pathlib.Path.cwd()/ 'build/worktree-rule-inheritance/qa-lab/acceptance-v1'
if root != expected or root.is_symlink():
    raise SystemExit('Select the explicitly approved acceptance-v1 lab from the package root.')
manifest=root/'manifests/lab.json'
owner='architecture-kit-worktree-inheritance-v1'
def git(path,*args):
    environment={k:v for k,v in os.environ.items() if not k.startswith('GIT_')}
    environment.update({'GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null'})
    return subprocess.check_output(['git','-c','core.hooksPath=/dev/null','-C',str(path),*args],stderr=subprocess.STDOUT,text=True,env=environment).strip()
def save(data):
    manifest.write_text(json.dumps(data,indent=2)+'\n')
def load():
    data=json.loads(manifest.read_text())
    if data.get('owner') != owner or data.get('root') != str(root):
        raise SystemExit('Lab ownership mismatch.')
    return data
if command=='prepare-a':
    if root.exists(): raise SystemExit('Lab already exists; inspect its manifest instead of overwriting it.')
    (root/'manifests').mkdir(parents=True,mode=0o700)
    (root/'evidence').mkdir();(root/'tool-home').mkdir(mode=0o700)
    data={'owner':owner,'root':str(root),'home':str(root/'tool-home'),'roots':{'main':{},'a':{},'b':{}}}
    # Publish ownership first, so interrupted preparation remains recoverable.
    save(data)
    registry={}
    for family in ['crm-backend','glenross']:
        main=root/'fixture-main'/family; a=root/'worktree-a'/family
        main.mkdir(parents=True);(main/'app').mkdir()
        (main/'composer.json').write_text('{"type":"project","require":{"php":"^8.3"}}\n')
        (main/'app/Witness.php').write_text('<?php namespace App; class Witness { public const VALUE = "MAIN"; public function onlyMain(): string { return self::VALUE; } }\n')
        git(main,'init','--initial-branch=main');git(main,'add','composer.json','app/Witness.php')
        git(main,'-c','user.name=Architecture Kit QA','-c','user.email=archkit-qa@example.invalid','-c','commit.gpgsign=false','commit','-m','Synthetic worktree fixture')
        a.parent.mkdir(exist_ok=True)
        git(main,'worktree','add','--detach',str(a),'HEAD')
        data['roots']['main'][family]=str(main);data['roots']['a'][family]=str(a);save(data)
        (a/'app/Witness.php').write_text('<?php namespace App; class Witness { public const VALUE = "A"; public function onlyA(): string { return self::VALUE; } }\n')
        (main/'app/ParentOnly.php').write_text('<?php namespace App; class ParentOnly {}\n')
        identity=hashlib.sha256(str(main).encode()).hexdigest()
        profile=root/'tool-home/profiles'/identity;profile.mkdir(parents=True,mode=0o700)
        settings={'enabled':['actions'],'analysis':{'engine':'php'},'audit':{'missing_test':'off','cache':True}}
        (profile/'default.json').write_text(json.dumps(settings))
        registry[identity]={'root':str(main),'config':str(profile/'default.json')}
    (root/'tool-home/projects.json').write_text(json.dumps(registry))
    print(manifest)
elif command=='add-b':
    data=load()
    if data['roots']['b']: raise SystemExit('B already exists.')
    for family,main in data['roots']['main'].items():
        b=root/'worktree-b'/family;b.parent.mkdir(exist_ok=True)
        git(main,'worktree','add','--detach',str(b),'HEAD')
        data['roots']['b'][family]=str(b);save(data)
        (b/'app/Witness.php').write_text('<?php namespace App; class Witness { public const VALUE = "B"; public function onlyB(): string { return self::VALUE; } }\n')
    print(manifest)
elif command=='clone-negative':
    data=load();source=data['roots']['main']['crm-backend'];clone=root/'foreign-clone/crm-backend'
    if clone.exists():raise SystemExit('Foreign clone already exists.')
    clone.parent.mkdir(exist_ok=True)
    git(root,'clone','--no-hardlinks',source,str(clone))
    data['foreign_clone']=str(clone);save(data);print(clone)
elif command=='status':
    data=load();print(json.dumps(data,indent=2))
elif command=='stop':
    load();print('No runtime processes are started by this fixture helper. Stop only owned runtime PIDs from the separate client manifest.')
elif command=='cleanup':
    data=load()
    for group in ['b','a']:
        for family,path in data['roots'][group].items():
            target=pathlib.Path(path)
            if target != root/('worktree-'+group)/family or target.is_symlink():
                raise SystemExit('Unsafe worktree cleanup path.')
            if target.exists():git(data['roots']['main'][family],'worktree','remove','--force',str(target))
    for name in ['fixture-main','worktree-a','worktree-b','tool-home','compat','foreign-clone']:
        target=root/name
        if target.is_symlink():raise SystemExit('Unsafe cleanup symlink.')
        if target.exists():shutil.rmtree(target)
    data['cleaned']=True;save(data);print('Owned fixtures removed; manifests and evidence retained.')
else:raise SystemExit('Use prepare-a, add-b, clone-negative, status, stop or cleanup.')
PY
