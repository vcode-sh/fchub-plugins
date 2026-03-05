import type { ReactNode } from "react";

type BlogSectionDividerProps = {
  children: ReactNode;
};

export function BlogSectionDivider({ children }: BlogSectionDividerProps) {
  return (
    <div className="flex items-center gap-2 mb-6">
      {children}
      <div className="flex-1 h-px bg-foreground/5" />
    </div>
  );
}
