FROM node:24-alpine AS builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build
RUN npm prune --production

FROM node:24-alpine
WORKDIR /app
ENV NODE_ENV=production

COPY --from=builder /app/dist ./dist
COPY --from=builder /app/node_modules ./node_modules
COPY --from=builder /app/package.json ./

LABEL org.opencontainers.image.title="FluentCart MCP Server"
LABEL org.opencontainers.image.description="Capability-discovered MCP tools for managing a FluentCart store via the Model Context Protocol"
LABEL org.opencontainers.image.source="https://github.com/vcode-sh/fchub-plugins"
LABEL org.opencontainers.image.licenses="MIT"
LABEL org.opencontainers.image.vendor="Vibe Code"
LABEL org.opencontainers.image.url="https://fchub.co/docs/fluentcart-mcp"

EXPOSE 3000

# The container binds 0.0.0.0 so the published port is reachable. That is a non-loopback
# exposure, so the server refuses to start unless FLUENTCART_MCP_API_KEY is injected at run
# time with at least 32 characters. Inject it with `-e FLUENTCART_MCP_API_KEY=...`; it is
# deliberately never baked into the image.
CMD ["node", "dist/index.js", "--transport", "http", "--port", "3000", "--host", "0.0.0.0"]
