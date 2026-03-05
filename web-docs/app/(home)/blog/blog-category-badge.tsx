import { Badge } from "@/components/ui/badge";
import { categoryColors, categoryLabels } from "./blog-listing.config";

type BlogCategoryBadgeProps = {
  category: string;
};

export function BlogCategoryBadge({ category }: BlogCategoryBadgeProps) {
  return (
    <Badge className={categoryColors[category]}>
      {categoryLabels[category] ?? category}
    </Badge>
  );
}
