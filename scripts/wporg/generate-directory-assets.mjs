#!/usr/bin/env node

import fs from "node:fs/promises";
import path from "node:path";
import { createRequire } from "node:module";

const repositoryRoot = path.resolve(import.meta.dirname, "../..");
const require = createRequire(import.meta.url);
const sharp = require(
  process.env.WPORG_SHARP_MODULE
    ?? path.join(repositoryRoot, "web-docs/node_modules/sharp"),
);

const fchubLogo = path.join(
  repositoryRoot,
  "web-docs/public/fchub-mobile-logo.webp",
);
const vibeCodeLogo = path.join(
  repositoryRoot,
  "web-docs/public/vcode/v-logo-dark.webp",
);
const extensionOutputRoot = path.join(repositoryRoot, "wporg/assets");
const hubOutputRoot = process.env.WPORG_HUB_ASSET_DIR
  ? path.resolve(process.env.WPORG_HUB_ASSET_DIR)
  : null;

const products = [
  {
    slug: "fchub-wishlist",
    title: "Wishlist",
    strapline: "Wishlists that feel native",
    accent: "#ff5f8f",
    accent2: "#8b5cf6",
    glyph:
      '<path d="M74 52c-15-22-48-12-48 17 0 27 48 54 48 54s48-27 48-54c0-29-33-39-48-17Z"/>',
  },
  {
    slug: "fchub-multi-currency",
    title: "Multi-Currency",
    strapline: "Clear currency, honest checkout",
    accent: "#45d8ff",
    accent2: "#476bff",
    glyph:
      '<circle cx="74" cy="78" r="48"/><path d="M26 78h96M74 30c-17 14-26 30-26 48s9 34 26 48M74 30c17 14 26 30 26 48s-9 34-26 48"/>',
  },
  {
    slug: "fchub-memberships",
    title: "Memberships",
    strapline: "Access that follows the member",
    accent: "#a78bfa",
    accent2: "#5576ff",
    glyph:
      '<circle cx="74" cy="55" r="20"/><path d="M34 126c3-29 18-44 40-44s37 15 40 44"/><path d="M20 108c2-18 11-29 26-34M128 108c-2-18-11-29-26-34"/>',
  },
  {
    slug: "fchub-p24",
    title: "Przelewy24",
    strapline: "A focused payment gateway for FluentCart",
    accent: "#ff6b6b",
    accent2: "#d6336c",
    glyph:
      '<rect x="20" y="38" width="108" height="78" rx="16"/><path d="M20 62h108M42 92h25M104 91l11 11 20-24"/>',
  },
  {
    slug: "fchub-fakturownia",
    title: "Fakturownia",
    strapline: "Invoices, corrections and KSeF",
    accent: "#4de2c5",
    accent2: "#3489ff",
    glyph:
      '<path d="M38 18h58l24 24v94H38Z"/><path d="M96 18v28h24M56 72h46M56 92h34M56 112h26"/><path d="m96 112 10 10 22-27"/>',
  },
];

const hub = {
  slug: "fchub",
  title: "fchub",
  strapline: "The hub nobody asked for, but everyone needed",
  accent: "#ff8a4c",
  accent2: "#2aa8ff",
  glyph: "",
};

function escapeXml(value) {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

function bannerSvg(product, width, height) {
  return Buffer.from(`
    <svg width="${width}" height="${height}" viewBox="0 0 772 250" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <radialGradient id="glowA" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse"
          gradientTransform="translate(650 38) rotate(135) scale(320 210)">
          <stop stop-color="${product.accent}" stop-opacity=".34"/>
          <stop offset=".7" stop-color="${product.accent}" stop-opacity=".05"/>
          <stop offset="1" stop-color="${product.accent}" stop-opacity="0"/>
        </radialGradient>
        <radialGradient id="glowB" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse"
          gradientTransform="translate(130 260) rotate(-28) scale(360 190)">
          <stop stop-color="${product.accent2}" stop-opacity=".2"/>
          <stop offset="1" stop-color="${product.accent2}" stop-opacity="0"/>
        </radialGradient>
        <linearGradient id="stroke" x1="24" y1="18" x2="130" y2="136" gradientUnits="userSpaceOnUse">
          <stop stop-color="white" stop-opacity=".96"/>
          <stop offset="1" stop-color="${product.accent}" stop-opacity=".9"/>
        </linearGradient>
        <pattern id="grid" width="32" height="32" patternUnits="userSpaceOnUse">
          <path d="M32 0H0V32" fill="none" stroke="white" stroke-opacity=".035"/>
        </pattern>
      </defs>
      <rect width="772" height="250" fill="#080b14"/>
      <rect width="772" height="250" fill="url(#glowA)"/>
      <rect width="772" height="250" fill="url(#glowB)"/>
      <rect width="772" height="250" fill="url(#grid)"/>
      <path d="M0 1H772" stroke="white" stroke-opacity=".08"/>
      <path d="M0 249H772" stroke="black" stroke-opacity=".45"/>

      <g transform="translate(578 45)" fill="none" stroke="url(#stroke)" stroke-width="4"
        stroke-linecap="round" stroke-linejoin="round" opacity=".94">
        ${product.glyph}
      </g>
      <circle cx="652" cy="123" r="91" fill="none" stroke="white" stroke-opacity=".09"/>
      <circle cx="652" cy="123" r="109" fill="none" stroke="white" stroke-opacity=".045"/>

      <text x="184" y="58" fill="#aab2c7" font-family="Inter, Arial, sans-serif"
        font-size="12" font-weight="700" letter-spacing="2.3">FCHUB</text>
      <text x="184" y="112" fill="white" font-family="Inter, Arial, sans-serif"
        font-size="38" font-weight="720" letter-spacing="-1.3">${escapeXml(product.title)}</text>
      <text x="184" y="145" fill="#c9d0df" font-family="Inter, Arial, sans-serif"
        font-size="15" font-weight="430">${escapeXml(product.strapline)}</text>
      <line x1="184" y1="177" x2="420" y2="177" stroke="white" stroke-opacity=".12"/>
      <text x="184" y="207" fill="#8e98ad" font-family="Inter, Arial, sans-serif"
        font-size="10" font-weight="700" letter-spacing="1.7">WORDPRESS, PROPERLY EXTENDED</text>
    </svg>
  `);
}

function productBadgeSvg(product, size) {
  const badge = Math.round(size * 0.34);
  const left = size - badge - Math.round(size * 0.045);
  const top = size - badge - Math.round(size * 0.045);
  const glyphScale = badge / 174;

  return Buffer.from(`
    <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <linearGradient id="badge" x1="${left}" y1="${top}" x2="${left + badge}" y2="${top + badge}">
          <stop stop-color="${product.accent}"/>
          <stop offset="1" stop-color="${product.accent2}"/>
        </linearGradient>
      </defs>
      <circle cx="${left + badge / 2}" cy="${top + badge / 2}" r="${badge / 2}"
        fill="#080b14" stroke="white" stroke-opacity=".9" stroke-width="${Math.max(2, size * 0.014)}"/>
      <circle cx="${left + badge / 2}" cy="${top + badge / 2}" r="${badge * 0.42}"
        fill="url(#badge)" fill-opacity=".96"/>
      <g transform="translate(${left + badge * 0.08} ${top + badge * 0.07}) scale(${glyphScale})"
        fill="none" stroke="white" stroke-width="8" stroke-linecap="round" stroke-linejoin="round">
        ${product.glyph}
      </g>
    </svg>
  `);
}

async function writeBanner(product, outputDirectory, width, height) {
  const scale = width / 772;
  const fchubMark = await sharp(fchubLogo)
    .resize(Math.round(116 * scale), Math.round(116 * scale))
    .png()
    .toBuffer();
  const authorMark = await sharp(vibeCodeLogo)
    .resize({ width: Math.round(112 * scale) })
    .png()
    .toBuffer();

  await sharp(bannerSvg(product, width, height))
    .composite([
      {
        input: fchubMark,
        left: Math.round(44 * scale),
        top: Math.round(67 * scale),
      },
      {
        input: authorMark,
        left: Math.round(624 * scale),
        top: Math.round(201 * scale),
      },
    ])
    .removeAlpha()
    .png({ palette: false })
    .toFile(path.join(outputDirectory, `banner-${width}x${height}.png`));
}

async function writeIcon(product, outputDirectory, size) {
  const iconBase = await sharp(fchubLogo)
    .resize(size, size)
    .png({ palette: false })
    .toBuffer();
  const overlays = product.glyph
    ? [{ input: productBadgeSvg(product, size), left: 0, top: 0 }]
    : [];
  const approvedComposite = await sharp(iconBase)
    .composite(overlays)
    .png()
    .toBuffer();

  await sharp(approvedComposite)
    .flatten({ background: "#080b14" })
    .png({ palette: false })
    .toFile(path.join(outputDirectory, `icon-${size}x${size}.png`));
}

async function writeProductAssets(product, outputDirectory) {
  await fs.mkdir(outputDirectory, { recursive: true });
  await Promise.all([
    writeIcon(product, outputDirectory, 128),
    writeIcon(product, outputDirectory, 256),
    writeBanner(product, outputDirectory, 772, 250),
    writeBanner(product, outputDirectory, 1544, 500),
  ]);
}

for (const product of products) {
  await writeProductAssets(
    product,
    path.join(extensionOutputRoot, product.slug),
  );
}

if (hubOutputRoot) {
  await writeProductAssets(hub, hubOutputRoot);
}
