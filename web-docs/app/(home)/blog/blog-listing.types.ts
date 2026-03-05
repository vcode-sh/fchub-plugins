export type BlogPost = {
  title: string;
  description: string;
  url: string;
  slug: string;
  date: string;
  category: string;
  image?: string;
  video?: string;
  featured: boolean;
  pinned: boolean;
  pinOrder?: number;
  readingTime: number;
};
