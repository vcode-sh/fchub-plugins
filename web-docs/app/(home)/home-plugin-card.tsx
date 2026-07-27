import { BookOpen, Download, Flame } from "lucide-react";
import Link from "next/link";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import type { HomePlugin } from "./home-plugin-catalog";

type HomePluginCardProps = {
  plugin: HomePlugin;
};

function HomePluginActions({ plugin }: HomePluginCardProps) {
  if (plugin.comingSoon) {
    return (
      <>
        <Button variant="secondary" size="xs" disabled>
          <BookOpen />
          Docs
        </Button>
        <Button variant="outline" size="xs" disabled>
          <Download />
          Download
        </Button>
      </>
    );
  }

  return (
    <>
      <Button
        variant="secondary"
        size="xs"
        render={<Link href={plugin.docsHref} />}
      >
        <BookOpen />
        Docs
      </Button>
      {plugin.downloadUrl && (
        <Button
          variant="outline"
          size="xs"
          render={
            <a
              href={plugin.downloadUrl}
              target="_blank"
              rel="noopener noreferrer"
            />
          }
        >
          <Download />
          Download
        </Button>
      )}
    </>
  );
}

export function HomePluginCard({ plugin }: HomePluginCardProps) {
  const Icon = plugin.icon;

  return (
    <Card
      className={
        plugin.comingSoon
          ? "h-full gap-0 py-0 opacity-50 pointer-events-none"
          : "h-full gap-0 py-0"
      }
    >
      <CardHeader className="bg-muted/50 rounded-t-xl py-3">
        <div className="flex items-center gap-2">
          <Icon className="size-4" />
          <CardTitle>{plugin.title}</CardTitle>
          {plugin.comingSoon && <Badge variant="secondary">Coming Soon</Badge>}
          {plugin.discontinued && (
            <Badge variant="destructive" className="ml-auto text-[10px] h-4">
              Discontinued
            </Badge>
          )}
          {plugin.hot && (
            <Badge className="ml-auto text-[10px] h-4 bg-orange-500/15 text-orange-500 border-transparent">
              <Flame size={10} />
              Hot
            </Badge>
          )}
        </div>
      </CardHeader>
      <CardContent className="pt-4 pb-6">
        <CardDescription>{plugin.description}</CardDescription>
      </CardContent>
      <CardFooter className="gap-2 mt-auto justify-end bg-muted/50 rounded-b-xl py-3">
        <HomePluginActions plugin={plugin} />
      </CardFooter>
    </Card>
  );
}
