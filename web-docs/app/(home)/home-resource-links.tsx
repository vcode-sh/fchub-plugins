"use client";

import { Bot, Braces } from "lucide-react";
import { motion } from "motion/react";
import Link from "next/link";
import { Badge } from "@/components/ui/badge";
import {
  Card,
  CardContent,
  CardDescription,
  CardTitle,
} from "@/components/ui/card";
import {
  containerVariants,
  FLUENTCART_API_URL,
  itemVariants,
} from "./home-page.config";

export function HomeResourceLinks() {
  return (
    <motion.div
      initial="hidden"
      animate="visible"
      variants={containerVariants}
      className="max-w-4xl w-full mt-4 space-y-3"
    >
      <motion.div variants={itemVariants}>
        <Link href="/fluentcart-mcp" className="block group">
          <Card className="gap-0 py-0 transition-colors hover:border-primary/30">
            <CardContent className="flex items-center gap-3 py-3">
              <Bot className="size-4 text-muted-foreground group-hover:text-primary transition-colors" />
              <div className="flex-1">
                <CardTitle className="text-sm">FluentCart MCP Server</CardTitle>
                <CardDescription className="text-xs">
                  Read store data by default, then opt in only to reviewed
                  reversible administration. Store availability depends on
                  served routes and user permissions.
                </CardDescription>
              </div>
              <Badge variant="secondary" className="text-[10px] h-4">
                New
              </Badge>
            </CardContent>
          </Card>
        </Link>
      </motion.div>
      <motion.div variants={itemVariants}>
        <Link
          href={FLUENTCART_API_URL}
          target="_blank"
          rel="noopener noreferrer"
          className="block group"
        >
          <Card className="gap-0 py-0 transition-colors hover:border-primary/30">
            <CardContent className="flex items-center gap-3 py-3">
              <Braces className="size-4 text-muted-foreground group-hover:text-primary transition-colors" />
              <div className="flex-1">
                <CardTitle className="text-sm">REST API Reference</CardTitle>
                <CardDescription className="text-xs">
                  Official REST API documentation maintained by FluentCart.
                </CardDescription>
              </div>
              <Badge variant="secondary" className="text-[10px] h-4">
                New
              </Badge>
            </CardContent>
          </Card>
        </Link>
      </motion.div>
    </motion.div>
  );
}
