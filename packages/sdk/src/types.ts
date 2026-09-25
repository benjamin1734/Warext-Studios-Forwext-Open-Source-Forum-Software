import type {
  ForwextApiEndpointName,
  ForwextApiResource,
  ForwextApiScope,
} from "./generated/contract.js";

export type ForwextEntityId = string;
export type ForwextIsoTimestamp = string;

export interface ApiEnvelope<T> {
  data: T;
}

export interface ApiPagination {
  page: number;
  per_page: number;
  has_more: boolean;
}

export interface ApiPage<T> {
  items: T[];
  pagination: ApiPagination;
}

export interface ApiErrorBody {
  error: {
    code: string;
    message: string;
    details?: Record<string, unknown>;
  };
}

export interface ApiServiceEndpoint {
  name: ForwextApiEndpointName;
  methods: string[];
  path: string;
  resource: ForwextApiResource | null;
  scope: ForwextApiScope | null;
  public: boolean;
}

export interface ApiServiceDocument {
  version: string;
  resources: ForwextApiResource[];
  scopes: ForwextApiScope[];
  endpoints: ApiServiceEndpoint[];
}

export interface User {
  id: ForwextEntityId;
  username: string;
  created_at: ForwextIsoTimestamp;
  updated_at: ForwextIsoTimestamp;
}

export interface Forum {
  id: ForwextEntityId;
  parent_id: ForwextEntityId | null;
  title: string;
  slug: string;
  description: string;
  sort_order: number;
}

export interface Thread {
  id: ForwextEntityId;
  forum_id: ForwextEntityId;
  author_user_id: ForwextEntityId | null;
  type: string;
  title: string;
  locked: boolean;
  sticky: boolean;
  featured: boolean;
  created_at: ForwextIsoTimestamp;
  updated_at: ForwextIsoTimestamp;
}

export interface Post {
  id: ForwextEntityId;
  thread_id: ForwextEntityId;
  author_user_id: ForwextEntityId | null;
  position: number;
  body_source: string;
  created_at: ForwextIsoTimestamp;
  updated_at: ForwextIsoTimestamp;
}

export interface ConversationSummary {
  id: ForwextEntityId;
  type: "support" | "bug";
  title: string;
  status: string;
  updated_at: ForwextIsoTimestamp;
}

export interface Notification {
  id: ForwextEntityId;
  type: string;
  category: string;
  title: string;
  body: string;
  action_path: string | null;
  occurrences: number;
  created_at: ForwextIsoTimestamp;
  updated_at: ForwextIsoTimestamp;
  read_at: ForwextIsoTimestamp | null;
}

export interface ModuleSummary {
  key: string;
  state: string;
}

export interface MarketplaceListing {
  id: ForwextEntityId;
  seller_user_id: ForwextEntityId;
  category_id: ForwextEntityId;
  slug: string;
  title: string;
  description: string;
  price_minor: number;
  currency: string;
  state: string;
  created_at: ForwextIsoTimestamp;
  updated_at: ForwextIsoTimestamp;
}

export interface SupportCategory {
  key: string;
  label: string;
  description: string;
  default_priority: string;
  sort_order: number;
}

export interface SupportTicket {
  id: ForwextEntityId;
  category: string;
  subject: string;
  priority: string;
  status: string;
  created_at: ForwextIsoTimestamp;
  updated_at: ForwextIsoTimestamp;
  resolved_at: ForwextIsoTimestamp | null;
  closed_at: ForwextIsoTimestamp | null;
}
