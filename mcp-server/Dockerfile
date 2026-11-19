FROM node:20-alpine

WORKDIR /app
COPY package*.json ./
RUN npm ci --omit=dev
COPY src ./src

ENV NODE_ENV=production
ENV MCP_TRANSPORT=http
ENV XCLIPSE_TOOL_MODE=compact
ENV PORT=3000

EXPOSE 3000
CMD ["node", "src/index.js", "--http"]
