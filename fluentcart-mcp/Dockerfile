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

# The published port uses the private profile. Inject FLUENTCART_MCP_API_KEY (32+ bytes),
# FLUENTCART_MCP_ALLOWED_HOSTS and FLUENTCART_MCP_ALLOWED_ORIGINS at run time; none belongs
# in the image. The two allowlists are comma-separated, hostname-only values.
CMD ["node", "dist/index.js", "--transport", "http", "--port", "3000", "--host", "0.0.0.0", "--http-profile", "private"]
