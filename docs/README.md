# Documentation

The [VitePress](https://vitepress.dev) source for the Laravel Backfill docs.

```bash
cd docs
npm install
npm run dev      # http://localhost:5173
npm run build    # also fails on dead internal links
npm run preview
```

## Layout

```
docs/
  index.md              home page
  changelog.md
  guide/                introduction, installation, writing, running
  safety/               the invariant, keyset, transactions, failures, throttling, guards
  features/             dry run, queue, dashboard, operator panel, pulse, events, notifications
  advanced/             side effects, multi-tenancy, testing
  reference/            commands, API, parameters, configuration, schema
  public/               static assets
  .vitepress/config.ts  nav, sidebar, theme
```

## Notes

Adding a page means adding it to the sidebar in `.vitepress/config.ts` — VitePress does not generate one.

`npm run build` fails on a dead internal link, so it is the link checker as well as the build. CI runs it on every pull request touching `docs/`, and deploys to GitHub Pages from `main`.

The `base` in `.vitepress/config.ts` is `/laravel-backfill/`, which suits a project Pages site. Set it to `/` if you serve the docs from a domain root.
