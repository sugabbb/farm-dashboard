export type AuthUser = {
  id: number;
  name: string;
  email: string;
  role: 'ADMIN' | 'SUPERADMIN';
  is_superadmin: boolean;
  must_change_password: boolean;
};

export type SharedProps = {
  auth: { user: AuthUser | null };
  flash: { success?: string | null };
  errors: Record<string, string>;
  today: string;
  defaultLeleAdg?: string;
};

export type Pagination<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
  first_page_url: string;
  last_page_url: string;
  next_page_url: string | null;
  prev_page_url: string | null;
};

export type Option = { value: string | number; label: string; isDisabled?: boolean };
