// Typed wrapper around versions.json.
// CI/scripts read versions.json directly (no TS runtime needed).
// App code imports this file.

import data from "./versions.json";

const GITHUB_REPO = "https://github.com/vcode-sh/fchub-plugins";

export type PluginSlug = keyof typeof data.plugins;

export type PluginVersion = {
  version: string;
  tagName: string;
  releaseUrl: string;
  downloadUrl?: string;
  zipFilename?: string;
};

export const versions = Object.fromEntries(
  Object.entries(data.plugins).map(([slug, raw]) => [
    slug,
    {
      version: raw.version,
      tagName: raw.tagName,
      releaseUrl: `${GITHUB_REPO}/releases/tag/${raw.tagName}`,
      downloadUrl: raw.mcpbFilename
        ? `${GITHUB_REPO}/releases/download/${raw.tagName}/${raw.mcpbFilename}`
        : undefined,
      zipFilename: raw.zipFilename ?? undefined,
    },
  ]),
) as Record<PluginSlug, PluginVersion>;

export const mcpToolCount = data.mcp.toolCount;
export const mcpModuleCount = data.mcp.moduleCount;
