import type { LucideProps } from 'lucide-react';

export function ChickenIcon({ size = 24, color = 'currentColor', strokeWidth = 2, ...props }: LucideProps) {
  return <svg
    xmlns="http://www.w3.org/2000/svg"
    width={size}
    height={size}
    viewBox="0 0 24 24"
    fill="none"
    stroke={color}
    strokeWidth={strokeWidth}
    strokeLinecap="round"
    strokeLinejoin="round"
    aria-hidden="true"
    {...props}
  >
    <path d="M7.5 10.2C5.2 10.8 3.7 13 4 15.5c.3 2.8 2.7 4.8 5.5 4.5h3.8c3.1 0 5.7-2.5 5.7-5.7V11"/>
    <path d="M18.9 11c1.6 0 2.8-1.2 2.8-2.6l-2.9-.8"/>
    <path d="M18.7 7.7c-.2-1.8-1.7-3.2-3.6-3.2-2 0-3.6 1.6-3.6 3.6v3.3"/>
    <path d="M13.3 4.9c-.2-1 .4-1.8 1.4-2.4.3 1.1 1 1.6 2.1 1.9"/>
    <path d="M7.9 10.1C6.5 8.4 4.5 7.5 2.3 7.8c.3 1.6 1.2 2.9 2.6 3.7-1.2.5-2 1.4-2.4 2.7"/>
    <path d="M9 14c1.3-1.1 3.1-1 4.2.2-1.3 1.1-3.1 1-4.2-.2Z"/>
    <circle cx="16.8" cy="7.4" r=".55" fill="currentColor" stroke="none"/>
    <path d="M8 20v2M14 20v2M6.8 22h2.4M12.8 22h2.4"/>
  </svg>;
}
