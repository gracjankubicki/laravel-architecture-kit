#!/usr/bin/env bash
set -euo pipefail
# An operator explicitly prepares and later removes Git fixtures. No implicit worktree creation.
if [ "$#" -ne 1 ] || [ ! -f "$1" ]; then
    echo 'OPEN: pass an explicitly prepared worktree lab manifest.' >&2
    exit 2
fi
export ARCHITECTURE_KIT_WORKTREE_MANIFEST="$1"
composer test:filter -- 'GitFamilyTest|ConfigurationResolverTest|WorktreeConfigurationTest|WorktreeMcpProcessTest|WorktreeRefreshTest|WorktreeIntegrationExportTest' -d memory_limit=512M
