# Stage 1: Install dependencies
# Bun is a superb package manager, and this stage is all it does — install.
# Pinned rather than floating on "1": a base image that moves under you is how
# a build that worked on Friday segfaults on Monday with nothing to blame.
FROM oven/bun:1.3.14-slim AS deps
WORKDIR /app

# Copy package files, lockfile, and files needed for fumadocs-mdx postinstall
COPY package.json bun.lock source.config.ts ./
COPY content ./content

# Install dependencies (fumadocs-mdx runs during postinstall and needs content/)
RUN bun install --frozen-lockfile

# Stage 2: Build the application
# Node, not Bun. Bun panics on teardown after `next build` completes, turning a
# finished build into exit code 132 — the artefacts are fine, the process is
# not, and CI cannot tell the difference. Node runs Next the way Next expects.
# Debian-based, matching the deps stage's libc so native binaries stay valid.
FROM node:26-slim AS builder
WORKDIR /app

# Build-time variables (NEXT_PUBLIC_* must be set at build time!)
ARG NEXT_PUBLIC_URL=https://fchub.co

# Copy dependencies from deps stage
COPY --from=deps /app/node_modules ./node_modules
COPY --from=deps /app/.source ./.source

# Copy all source files
COPY . .

# Set environment for build
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV NEXT_PUBLIC_URL=${NEXT_PUBLIC_URL}

# Build Next.js directly rather than through `bun run build`, so the package
# script stays Bun's for local work while the image never hands Next to Bun.
RUN node ./node_modules/next/dist/bin/next build

# Stage 3: Production runner
# Debian again, so the native binaries traced into the standalone bundle meet
# the same libc they were installed against. Alpine plus glibc deps is a bet.
FROM node:26-slim AS runner
WORKDIR /app

# Set production environment
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV PORT=3000
ENV HOSTNAME=0.0.0.0

# Create non-root user for security (the stock `node` user already holds 1000)
RUN groupadd -g 1001 nodejs && \
    useradd -u 1001 -g nodejs -m -s /usr/sbin/nologin nextjs

# Copy only necessary files from builder
COPY --from=builder --chown=nextjs:nodejs /app/public ./public
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static

# Switch to non-root user
USER nextjs

# Expose port
EXPOSE 3000

# Health check through Node itself, pinned to IPv4. The slim images carry
# neither wget nor curl, and the previous IPv6-first wget was already a
# workaround for exactly this.
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:3000/').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

# Start the application
CMD ["node", "server.js"]
