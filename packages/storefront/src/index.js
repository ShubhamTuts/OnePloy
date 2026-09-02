export function createStorefrontClient({ baseUrl }) {
  const root = String(baseUrl || '').replace(/\/$/, '');

  async function get(path) {
    const response = await fetch(`${root}${path}`);
    if (!response.ok) {
      throw new Error(`OnePloy storefront ${response.status}`);
    }
    return response.json();
  }

  return {
    catalogue: (currency, interval = 'monthly') => {
      const params = new URLSearchParams({ interval });
      if (currency) params.set('currency', currency);
      return get(`/api/storefront/v1/catalogue?${params}`);
    },
    applications: () => get('/api/storefront/v1/applications'),
    searchDomain: (q) => get(`/api/storefront/v1/domains/search?q=${encodeURIComponent(q)}`),
    status: () => get('/api/storefront/v1/status'),
    checkoutStatus: (id) => get(`/api/storefront/v1/checkout/${id}`),
  };
}
