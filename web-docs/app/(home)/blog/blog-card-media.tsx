import Image from "next/image";
import type { BlogPost } from "./blog-listing.types";

type BlogCardMediaProps = {
  post: BlogPost;
  sizes: string;
};

export function BlogCardMedia({ post, sizes }: BlogCardMediaProps) {
  if (post.video) {
    return (
      <video
        autoPlay
        loop
        muted
        playsInline
        className="absolute inset-0 h-full w-full object-cover"
        src={post.video}
      />
    );
  }

  if (post.image) {
    return (
      <Image
        src={post.image}
        alt={post.title}
        fill
        className="object-cover transition-transform duration-300 group-hover:scale-[1.03]"
        sizes={sizes}
      />
    );
  }

  return null;
}
