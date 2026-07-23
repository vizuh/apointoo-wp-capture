# Release Workflow

GitHub is the development source of truth. WordPress.org Subversion receives only a finished release built
from the matching Git tag.

## Version buffer

0.5.0 is the first WordPress.org release, so it may publish immediately after its release checks pass. From
0.6.0 onward, cut and test the release on staging for 3–5 days before replacing the live SVN tag.

## Cut the GitHub release

1. Update the `Version:` header in `apointoo-wp-capture.php`, `Stable tag:` and `== Changelog ==` in
   `readme.txt`.
2. Run the release checks and merge the release PR to `main`.
3. Tag the verified merge commit, then build from that exact tag:

   ```bash
   git fetch origin main
   git tag -a vVERSION origin/main -m "Release VERSION"
   git push origin vVERSION
   git switch --detach vVERSION
   bin/build.sh
   mkdir -p dist/releases/VERSION
   cp dist/apointoo-capture-VERSION.zip dist/releases/VERSION/
   sha256sum dist/releases/VERSION/apointoo-capture-VERSION.zip
   ```

4. Publish the ZIP from the same tag:

   ```bash
   gh release create vVERSION dist/releases/VERSION/apointoo-capture-VERSION.zip \
     --verify-tag \
     --title "vVERSION" \
     --generate-notes
   ```

## Publish to WordPress.org Subversion

Use a fresh checkout. For the first release, populate and commit `trunk`, then create the tag with SVN's own
copy operation. The WordPress.org SVN username is `hugoc`; use the SVN-specific password, never a GitHub token.

```bash
svn checkout https://plugins.svn.wordpress.org/apointoo-capture/ /tmp/svn-apointoo-capture
rm -rf /tmp/apointoo-capture-release
mkdir -p /tmp/apointoo-capture-release
unzip dist/releases/VERSION/apointoo-capture-VERSION.zip -d /tmp/apointoo-capture-release
rsync -a --delete /tmp/apointoo-capture-release/apointoo-capture/ /tmp/svn-apointoo-capture/trunk/

cd /tmp/svn-apointoo-capture
svn add --force trunk
svn status
svn diff
svn commit trunk -m "Release VERSION to trunk" --username hugoc

svn copy trunk tags/VERSION
svn status
svn commit tags/VERSION -m "Tag VERSION" --username hugoc
```

Before either commit, confirm that `trunk/readme.txt` has `Stable tag: VERSION`, the main plugin header has
`Version: VERSION`, and no development-only file from `.distignore` is present. A new tagged version also
requires confirmation from the WordPress.org Release Management email/dashboard before it becomes public.
For later updates, schedule every `!` path reported by `svn status` with `svn delete` before committing trunk.

## Verify parity

```bash
svn export --force https://plugins.svn.wordpress.org/apointoo-capture/tags/VERSION /tmp/apointoo-svn-tag
diff -qr /tmp/apointoo-capture-release/apointoo-capture /tmp/apointoo-svn-tag
svn info https://plugins.svn.wordpress.org/apointoo-capture/tags/VERSION
gh release view vVERSION
```

The `diff` must be empty. Keep the ZIP checksum with the release handoff.
