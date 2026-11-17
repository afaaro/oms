# Themes

Each folder under `themes/` is one selectable UI theme with its own assets.

```text
themes/
  classic/
    theme.json
    assets/
      css/
        theme.css      # required — colour variables / overrides
      js/              # optional — auto-loaded *.js
      img/             # optional — logos, backgrounds, etc.
  ocean/
    ...
```

## Add a theme

1. Copy `classic/` to a new folder name (`mytheme/`).
2. Edit `theme.json` (folder name = theme key).
3. Edit `assets/css/theme.css`.
4. Optionally add more CSS/JS/images under `assets/`.
5. Refresh — it appears in **Appearance** and the top-bar picker.

## Asset loading order

1. Core `assets/css/app.css` (shared layout)
2. `themes/{name}/assets/css/theme.css`
3. Any other `themes/{name}/assets/css/*.css`
4. Core JS (`datatables-init.js`, `nav-mobile.js`)
5. Any `themes/{name}/assets/js/*.js`

Reference theme images from CSS as relative paths, e.g. `url("../img/hero.jpg")`.
