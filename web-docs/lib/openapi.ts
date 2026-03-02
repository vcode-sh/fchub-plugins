import { createOpenAPI } from "fumadocs-openapi/server";

export const openapi = createOpenAPI({
  input: ["./content/docs/fluentcart-api/openapi.yaml"],
});
