import type { Metadata } from 'next'
import './globals.css'
import Link from 'next/link'

export const metadata: Metadata = {
  title: 'Payment Gateway Test App',
  description: 'Test UI for Payment Gateway SDK',
}

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="vi">
      <body className="bg-gray-50 min-h-screen">
        <nav className="bg-white border-b border-gray-200 px-6 py-3 flex items-center gap-6 shadow-sm">
          <Link href="/" className="font-bold text-gray-900 text-lg">
            Payment SDK Tester
          </Link>
          <Link href="/pay" className="text-sm text-gray-600 hover:text-indigo-600 transition-colors">
            Tạo thanh toán
          </Link>
          <Link href="/status" className="text-sm text-gray-600 hover:text-indigo-600 transition-colors">
            Tra cứu
          </Link>
          <Link href="/webhooks" className="text-sm text-gray-600 hover:text-indigo-600 transition-colors">
            Webhook Log
          </Link>
        </nav>
        <main className="max-w-5xl mx-auto px-6 py-8">{children}</main>
      </body>
    </html>
  )
}
