import { type PluginSlug, versions } from "@/lib/versions";

type Props = {
  plugin: PluginSlug;
  children?: React.ReactNode;
};

export function PluginDownload({ plugin, children }: Props) {
  const v = versions[plugin];
  return (
    <a href={v.releaseUrl} target="_blank" rel="noopener noreferrer">
      {children ?? `Download v${v.version}`}
    </a>
  );
}

export function McpbDownload({ children }: { children?: React.ReactNode }) {
  const v = versions["fluentcart-mcp"];
  return (
    <a href={v.downloadUrl} target="_blank" rel="noopener noreferrer">
      {children ?? "fluentcart-mcp.mcpb"}
    </a>
  );
}

export function PluginVersion({ plugin }: { plugin: PluginSlug }) {
  return <>{versions[plugin].version}</>;
}

export function PluginZip({ plugin }: { plugin: PluginSlug }) {
  return <>{versions[plugin].zipFilename}</>;
}
