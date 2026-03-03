import defaultMdxComponents from "fumadocs-ui/mdx";
import type { MDXComponents } from "mdx/types";
import { APIPage } from "@/components/api-page";
import {
	McpbDownload,
	PluginDownload,
	PluginVersion,
	PluginZip,
} from "@/components/plugin-download";

export function getMDXComponents(components?: MDXComponents): MDXComponents {
	return {
		...defaultMdxComponents,
		APIPage,
		PluginDownload,
		McpbDownload,
		PluginVersion,
		PluginZip,
		...components,
	};
}
