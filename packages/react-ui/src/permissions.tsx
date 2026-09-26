"use client";

import {
  createContext,
  useContext,
  useMemo,
  type ReactNode,
} from "react";

const PERMISSION_KEY = /^[a-z][a-z0-9_.-]{1,95}$/u;
const EMPTY_PERMISSIONS: ReadonlySet<string> = new Set<string>();
const PermissionContext = createContext<ReadonlySet<string> | null>(null);

export interface PermissionProviderProps {
  permissions: Iterable<string>;
  children: ReactNode;
}

export function PermissionProvider({ permissions, children }: PermissionProviderProps) {
  const snapshot = useMemo(() => {
    const normalized = new Set<string>();
    for (const permission of permissions) {
      const key = permission.trim().toLowerCase();
      if (!PERMISSION_KEY.test(key)) {
        throw new Error("Invalid Forwext permission key.");
      }
      normalized.add(key);
    }
    return normalized as ReadonlySet<string>;
  }, [permissions]);

  return <PermissionContext.Provider value={snapshot}>{children}</PermissionContext.Provider>;
}

export function usePermissions(): ReadonlySet<string> {
  return useContext(PermissionContext) ?? EMPTY_PERMISSIONS;
}

export function useCan(
  required: string | readonly string[],
  mode: "all" | "any" = "all",
): boolean {
  const permissions = usePermissions();
  const keys = typeof required === "string" ? [required] : [...required];
  if (keys.length === 0) return true;

  const normalized = keys.map((permission) => {
    const key = permission.trim().toLowerCase();
    if (!PERMISSION_KEY.test(key)) {
      throw new Error("Invalid Forwext permission key.");
    }
    return key;
  });

  return mode === "all"
    ? normalized.every((key) => permissions.has(key))
    : normalized.some((key) => permissions.has(key));
}

export interface CanProps {
  required: string | readonly string[];
  mode?: "all" | "any";
  fallback?: ReactNode;
  children: ReactNode;
}

export function Can({
  required,
  mode = "all",
  fallback = null,
  children,
}: CanProps) {
  return useCan(required, mode) ? <>{children}</> : <>{fallback}</>;
}
