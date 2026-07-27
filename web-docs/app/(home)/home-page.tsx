import { HomeHero } from "./home-hero";
import { HomePluginSection } from "./home-plugin-section";
import { HomeResourceLinks } from "./home-resource-links";

export function HomePage() {
  return (
    <div className="flex flex-col justify-center items-center flex-1 px-4 py-12">
      <HomeHero />

      <HomePluginSection title="FluentCart" collection="cart" />
      <HomeResourceLinks />

      <HomePluginSection
        title="FluentCommunity"
        collection="community"
        separated
      />
    </div>
  );
}
