/** @type {import('next').NextConfig} */
const nextConfig = {
  experimental: {
    serverComponentsExternalPackages: ['@payment-sdk/node'],
  },
}
module.exports = nextConfig
