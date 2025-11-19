import api from "./api";

export interface LoginResponse {
  token: string;
  user: any;
  tenant?: any;
}

export const login = async (
  email: string,
  password: string,
  tenantSlug: string
): Promise<LoginResponse> => {
  const response = await api.post("/api/login", {
    email,
    password,
    tenant_slug: tenantSlug,
  });

  return response.data;
};

export const getUser = async () => {
  const response = await api.get("/api/user");
  return response.data;
};

export const logout = async () => {
  return api.post("/api/logout");
};
