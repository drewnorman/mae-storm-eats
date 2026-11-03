export type Stage = "recipe" | "reviews";
export type QueueMode = "pending" | "retry" | "refresh";
export type BlockKind =
  | "captcha"
  | "login"
  | "rate_limited"
  | "service"
  | "unknown";

export interface Settings {
  wordpressHost?: string;
  username?: string;
  applicationPassword?: string;
}

export interface ConnectionState {
  origin?: string;
  username?: string;
  state?: "pending" | "verifying" | "verified" | "failed";
  message?: string;
  verifiedAt?: string | null;
  updatedAt?: string;
}

export interface Owner {
  id: string;
  username: string;
  profile_url: string;
}

export interface Pin {
  pin_id: string | number;
  pin_url: string;
  board_url?: string;
  board_urls?: string[];
}

export interface StageCounts {
  pending?: number;
  complete?: number;
  failed?: number;
}

export interface Inventory {
  recipe?: StageCounts;
  reviews?: StageCounts;
}

export interface RunState {
  version?: number;
  kind?: "capture" | "stage" | "idle";
  status?: string;
  phase?: string;
  stage?: Stage;
  mode?: QueueMode;
  boardUrl?: string;
  boardTabId?: number;
  windowId?: number;
  pins?: Pin[];
  legacyPins?: Pin[];
  queue?: Pin[];
  cursor?: number;
  discovered?: number;
  discoveryStable?: number;
  completed?: number;
  failed?: number;
  skipped?: number;
  currentPin?: string | number | null;
  tempTabId?: number | null;
  challengeTabId?: number | null;
  nextPinAt?: string | null;
  resumeNotBefore?: string | null;
  pauseRequested?: boolean;
  pinsSinceLongPause?: number;
  consecutiveBlocks?: number;
  manualReviewExcluded?: number;
  blockKind?: BlockKind | null;
  blockEvidence?: string | null;
  inventory?: Inventory;
  error?: string;
  message?: string;
  updatedAt?: string;
}

export interface PauseEntry {
  at: string;
  reason: string;
  blockKind: string;
  blockEvidence: string;
}

export interface FailureEntry {
  at: string;
  pinId: string | number;
  stage: Stage;
  reason: string;
}

export interface ImportError extends Error {
  status?: number;
  code?: string;
  failureKind?: string;
  blockKind?: BlockKind;
  blockEvidence?: string;
  challengeContext?: "capture" | "stage";
  challengeTabId?: number;
}

export interface BlockResult {
  blocked?: boolean;
  reason?: string;
  blockKind?: BlockKind;
  blockEvidence?: string;
}

export interface DiscoveryResult extends BlockResult {
  paused?: boolean;
  pins?: Pin[];
  owner?: Owner;
}

export interface ReviewPhoto {
  id: string;
  url: string;
}

export interface ReviewComment {
  id: string;
  photos?: ReviewPhoto[];
}

export interface ResolveResult extends BlockResult {
  owner?: Owner;
  pin_url?: string;
  board_url?: string;
  source_url?: string;
  comments?: ReviewComment[];
  comments_complete?: boolean;
}

export interface ApiResponse {
  authenticated?: boolean;
  stages?: Inventory;
  pins?: Pin[];
  status?: string;
  warnings?: string[];
  message?: string;
  code?: string;
}

export type ExtensionMessage =
  | { type: "ping" }
  | { type: "discover" }
  | { type: "resolveRecipe" | "resolveReviews"; pin: Pin }
  | { type: "discoveryProgress"; discovered: number; stable: number }
  | { type: "capture" | "resume"; tabId: number }
  | { type: "startStage"; stage: Stage; mode?: QueueMode; tabId: number }
  | { type: "prepareVerification"; settings: Settings }
  | { type: "connectionStatus" | "permissionDenied" | "status" | "pause" }
  | { type: "progress"; run: RunState }
  | { type: "connectionState"; connection: ConnectionState };
