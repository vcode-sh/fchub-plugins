# fchub.co website

Documentation site for all FCHub plugins. Built with [Next.js](https://nextjs.org), [Fumadocs](https://fumadocs.dev), and [Tailwind CSS](https://tailwindcss.com). Deployed as a standalone Docker container.

## Stack

- **Framework** — Next.js 16 with standalone output
- **Docs engine** — Fumadocs (MDX-based, with search)
- **Styling** — Tailwind CSS 4 + shadcn/ui components
- **Runtime** — Bun
- **Linting** — Biome

## Development

```bash
cd web-docs
bun install
bun run dev
```

The dev server runs on `http://localhost:3000`. Content lives in `content/docs/` as MDX files.

## Project structure

```
web-docs/
├── app/                  # Next.js app router pages and layouts
├── components/ui/        # shadcn/ui components
├── content/docs/         # MDX documentation content
│   ├── fchub/            # Core plugin docs
│   ├── fchub-stream/     # Video streaming plugin
│   ├── fchub-p24/        # Przelewy24 payment gateway
│   ├── fchub-fakturownia/# Invoice automation
│   ├── fchub-memberships/# Membership plans
│   └── cartshift/        # WooCommerce migration tool
├── lib/                  # Utilities and shared config
├── public/               # Static assets
└── source.config.ts      # Fumadocs MDX config
```

## Adding documentation

1. Create or edit MDX files in `content/docs/<plugin>/`
2. Update `meta.json` in the plugin folder to control sidebar order
3. Frontmatter follows the Fumadocs schema — `title` and `description` at minimum

## Docker

```bash
# Build and run
docker compose up --build

# Or manually
docker build -t fchub-docs .
docker run -p 3000:3000 fchub-docs
```

The image uses a multi-stage build: deps, build, then a minimal Alpine runner. `NEXT_PUBLIC_URL` defaults to `https://fchub.co` and can be overridden at build time.

## License

GPLv2 or later.

