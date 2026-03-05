import { type BlogFilterCategory, categories } from "./blog-listing.config";

type BlogCategoryFilterProps = {
  activeCategory: BlogFilterCategory;
  onChange: (category: BlogFilterCategory) => void;
};

export function BlogCategoryFilter({
  activeCategory,
  onChange,
}: BlogCategoryFilterProps) {
  return (
    <div className="flex items-center gap-2 mb-8">
      {categories.map((category) => (
        <button
          key={category.value}
          type="button"
          onClick={() => onChange(category.value)}
          className={`rounded-full px-4 py-1.5 text-sm font-medium transition-colors ${
            activeCategory === category.value
              ? "bg-primary text-primary-foreground"
              : "bg-muted text-muted-foreground hover:text-foreground"
          }`}
        >
          {category.label}
        </button>
      ))}
    </div>
  );
}
