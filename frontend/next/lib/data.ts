import "server-only";

import { cache } from "react";

import { createForwextClient } from "@/lib/forwext";

export const getForum = cache(async (id: string) => createForwextClient().forum(id));
export const getThread = cache(async (id: string) => createForwextClient().thread(id));
export const getUser = cache(async (id: string) => createForwextClient().user(id));
export const getMarketplaceListing = cache(async (id: string) =>
  createForwextClient().marketplaceListing(id),
);
