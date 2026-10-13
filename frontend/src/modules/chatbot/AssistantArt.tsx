/** Copy of the website chat avatar artwork (frontend/site/islands/chat/Avatar.tsx) for the admin preview. */
import { useId } from 'react';

/** Original illustrated assistant (counsellor with headset) — same artwork as chatbot_avatar_svg() in PHP. */
export function AssistantArt({ className }: { className?: string }) {
  const uid = useId().replace(/:/g, '');
  const bg = `${uid}bg`;
  const clip = `${uid}clip`;
  return (
    <svg viewBox="0 0 64 64" className={className} aria-hidden="true" focusable="false">
      <defs>
        <linearGradient id={bg} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#FFF4EE" />
          <stop offset="1" stopColor="#FAD3C2" />
        </linearGradient>
        <clipPath id={clip}>
          <circle cx="32" cy="32" r="32" />
        </clipPath>
      </defs>
      <g clipPath={`url(#${clip})`}>
        <rect width="64" height="64" fill={`url(#${bg})`} />
        <circle cx="50" cy="13" r="9" fill="#F05A28" opacity=".14" />
        <path d="M16.5 33c0-12 7-19.5 15.5-19.5S47.5 21 47.5 33v13h-31z" fill="#1E293B" />
        <path d="M8 64c1.2-11.5 10-18 24-18s22.8 6.5 24 18z" fill="#0B2A5B" />
        <path d="M25.5 46.5 32 55l6.5-8.5z" fill="#fff" />
        <path d="M29.5 49.5 32 55l2.5-5.5z" fill="#D03E16" />
        <path d="M28 40h8v6.5a4 4 0 0 1-8 0z" fill="#E3A27F" />
        <ellipse cx="32" cy="31.5" rx="10.2" ry="11.6" fill="#F2BC98" />
        <path d="M21.6 30.5c.6-8.8 5.4-13.3 10.9-13.3 6.2 0 10.2 4.6 10.4 12.1-3.4-.9-7.4-3.6-9.6-7.2-2.3 4.4-6.7 7.6-11.7 8.4z" fill="#1E293B" />
        <circle cx="27.9" cy="32.4" r="1.35" fill="#1E293B" />
        <circle cx="36.1" cy="32.4" r="1.35" fill="#1E293B" />
        <path d="M28.6 36.6c2 1.9 4.8 1.9 6.8 0" stroke="#9A3412" strokeWidth="1.5" fill="none" strokeLinecap="round" />
        <circle cx="25.4" cy="35.4" r="1.7" fill="#F05A28" opacity=".22" />
        <circle cx="38.6" cy="35.4" r="1.7" fill="#F05A28" opacity=".22" />
        <path d="M19.6 31.5a12.4 12.4 0 0 1 24.8 0" stroke="#F05A28" strokeWidth="2.3" fill="none" strokeLinecap="round" />
        <rect x="17.6" y="29.2" width="4.8" height="7.6" rx="2.2" fill="#F05A28" />
        <rect x="41.6" y="29.2" width="4.8" height="7.6" rx="2.2" fill="#F05A28" />
        <path d="M20.4 36.4c.3 3.6 2.9 5.6 6.9 6" stroke="#F05A28" strokeWidth="1.6" fill="none" strokeLinecap="round" />
        <circle cx="27.9" cy="42.4" r="1.5" fill="#D03E16" />
      </g>
    </svg>
  );
}

