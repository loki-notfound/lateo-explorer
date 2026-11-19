# Static files of the site

Copied into the webroot of the site. Nothing here is generated.

## Style
The pages use the lateo house style, version 0.2.0 of 2026-11-18 (commit 41fff23 of
lateo-style, which has no tag). Unchanged copies, adopted on 2026-11-19:

- `content.css`, `forms.css`, `data.css` and `print.css`: the stylesheets of the
  server-rendered tier. They carry the tokens, the layout, the forms, the tables, the key-value
  lists, the status badges, the stat tiles and the print rules.
- `favicon.svg`: the favicon of the explorer.
- `lockup-host-explorer.svg` and `lockup-host-explorer-reversed.svg`: the lockup with the host
  name, for light and dark surfaces.

The PHP side of the style is in `style/` at the root of the repository, also unchanged:
`helpers.php`, `layout.php`, `products.json` and the partials the pages use (`breadcrumbs`,
`button`, `callout`, `family-bar`, `footer`, `header`, `status-badge`).

The favicon and the lockups are lateo marks and fall outside the repository's licence
([TRADEMARKS.md](../../TRADEMARKS.md)).

`explorer.css` holds only what the style does not have: the spacing of the links to the
neighbouring blocks, a list of hashes and the lockup as a block. To change the look, change the
house style and copy the new version in. The pages link each file with a mark of its content
(`?v=`), so a changed file is fetched anew.

## Script
There is none. The pages work without scripts, and nothing here loads from another host.

## Headers
The pages send the Content Security Policy and the other security headers themselves
(`src/Web/Response.php`). `.htaccess` in the parent directory routes every path that is not a
file to `index.php`, refuses dot files and sets `X-Content-Type-Options` for static files.
