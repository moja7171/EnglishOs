#!/bin/bash
# Rebuilds vendor/ + public/build inside the persistent `deploy` branch
# worktree, merges in whatever's new on main, commits, and pushes.
#
# One-time setup this depends on:
#   - a `deploy` branch + worktree at ~/englishos-deploy-worktree
#   - a GitHub push credential (personal access token) configured for
#     this machine's `git push` to work at all
#   - .cpanel.yml on that branch, and a cPanel Git Version Control repo
#     cloned from it (see the deployment guide artifact)
#
# After running this, the update reaches the live site once you click
# "Update from Remote" / "Pull or Deploy" in cPanel's Git Version
# Control screen for this repo — that one click is what's left of the
# old copy-paste-a-zip workflow.

set -euo pipefail

MAIN_REPO="/var/www/EnglishOS"
WORKTREE="/home/moja/englishos-deploy-worktree"

if [ ! -d "$WORKTREE" ]; then
    echo "Worktree not found at $WORKTREE — has it been removed?" >&2
    exit 1
fi

cd "$MAIN_REPO"
git fetch origin main

cd "$WORKTREE"
git merge origin/main --no-edit

echo "--- composer install ---"
composer install --no-dev --optimize-autoloader --no-interaction

echo "--- npm build ---"
npm ci --no-audit --no-fund
npm run build

git add -Af vendor public/build

if git diff --cached --quiet; then
    echo "vendor/public-build unchanged — nothing new to commit there."
else
    git commit -m "Deploy snapshot: refresh vendor/public-build"
fi

echo "--- pushing deploy branch ---"
git push origin deploy

echo
echo "Pushed. Now go click 'Update from Remote' (or 'Pull or Deploy') in"
echo "cPanel's Git Version Control screen for this repo to actually"
echo "update the live site."
