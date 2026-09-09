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

cd "$WORKTREE"
# Merges the LOCAL main branch, not origin/main — this worktree shares
# the same repo/refs as $MAIN_REPO, so local commits on main are
# already visible here without needing a push to GitHub first. Merging
# origin/main instead was a real bug: any local main commit not yet
# pushed to GitHub silently never made it into a deploy.
git merge main --no-edit

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

# Ships whatever Pexels has already cached HERE (this machine has clean,
# direct access to Pexels — production doesn't) as real files, so
# production's disk already has them before Laravel ever boots a
# request. PexelsClient::fetchAndCache() checks the file's existence
# before ever calling out — a file already on disk means production's
# `db:seed --force` (MissionSeeder::warmPexelsCache()) finds a cache hit
# and never touches Pexels' API itself. See
# feedback_never_fetch_pexels_live_on_site (memory) — the host must
# NEVER connect to Pexels, not even during a deploy's own seed step.
# Force-added the same way as vendor/public-build above: both stay
# gitignored on main (storage/app/public/.gitignore's blanket "*" also
# keeps genuinely dynamic content — avatars, user recordings — out of
# git on every branch), this is deploy-branch-only.
git add -Af storage/app/public/vocabulary-images storage/app/public/ambient-videos

if git diff --cached --quiet; then
    echo "Pexels image/video cache unchanged — nothing new to commit there."
else
    git commit -m "Deploy snapshot: refresh cached Pexels images/videos"
fi

echo "--- pushing deploy branch ---"
git push origin deploy

echo
echo "Pushed. Now go click 'Update from Remote' (or 'Pull or Deploy') in"
echo "cPanel's Git Version Control screen for this repo to actually"
echo "update the live site."
