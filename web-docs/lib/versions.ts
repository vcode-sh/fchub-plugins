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
      // Tag-scoped, not `/releases/latest`: this monorepo tags per plugin, so "latest" is
      // whichever plugin shipped most recently and would not carry this asset at all. The tag
      // here follows versions.json, which the release process bumps — no version is hand-typed
      // into prose anywhere.
      downloadUrl: raw.mcpbFilename
        ? `${GITHUB_REPO}/releases/download/${raw.tagName}/${raw.mcpbFilename}`
        : undefined,
      zipFilename: raw.zipFilename ?? undefined,
    },
  ]),
) as Record<PluginSlug, PluginVersion>;

// Definitions in the MCP source registry, from the generated release contract. This is not the
// number of tools a store exposes — write mode, discovered routes and user permissions all cut
// into it — so it must not be presented as "what you get". See versions.json for the full caveat.
export const mcpSourceDefinitionCount = data.mcp.toolCount;
export const mcpCategoryCount = data.mcp.moduleCount;

/** @deprecated Reads as a product claim it cannot support. Use {@link mcpSourceDefinitionCount}. */
export const mcpToolCount = data.mcp.toolCount;
/** @deprecated Use {@link mcpCategoryCount}. */
export const mcpModuleCount = data.mcp.moduleCount;
