FROM node:22-alpine AS builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build
RUN npm prune --production

FROM node:22-alpine
WORKDIR /app
ENV NODE_ENV=production

COPY --from=builder /app/dist ./dist
COPY --from=builder /app/node_modules ./node_modules
COPY --from=builder /app/package.json ./

LABEL org.opencontainers.image.title="FluentCart MCP Server"
LABEL org.opencontainers.image.description="200+ tools for managing your FluentCart store via Model Context Protocol"
LABEL org.opencontainers.image.source="https://github.com/vcode-sh/fchub-plugins"
LABEL org.opencontainers.image.licenses="MIT"
LABEL org.opencontainers.image.vendor="Vibe Code"
LABEL org.opencontainers.image.url="https://fchub.co/docs/fluentcart-mcp"

EXPOSE 3000
CMD ["node", "dist/index.js", "--transport", "http", "--port", "3000"]
