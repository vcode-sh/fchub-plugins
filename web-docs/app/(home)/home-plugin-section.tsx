"use client";

import { motion } from "motion/react";
import { containerVariants, itemVariants } from "./home-page.config";
import { HomePluginCard } from "./home-plugin-card";
import { cartPlugins, communityPlugins } from "./home-plugin-catalog";

type HomePluginSectionProps = {
  title: string;
  collection: "cart" | "community";
  separated?: boolean;
};

export function HomePluginSection({
  title,
  collection,
  separated = false,
}: HomePluginSectionProps) {
  const plugins = collection === "cart" ? cartPlugins : communityPlugins;

  return (
    <>
      <motion.div
        initial="hidden"
        animate="visible"
        variants={itemVariants}
        className={`max-w-4xl w-full mb-6${separated ? " mt-10" : ""}`}
      >
        <h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wider mb-4">
          {title}
        </h2>
      </motion.div>
      <motion.div
        initial="hidden"
        animate="visible"
        variants={containerVariants}
        className="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-4xl w-full"
      >
        {plugins.map((plugin) => (
          <motion.div key={plugin.title} variants={itemVariants}>
            <HomePluginCard plugin={plugin} />
          </motion.div>
        ))}
      </motion.div>
    </>
  );
}
