import clsx from 'clsx';
import { Play } from 'lucide-react';
import { useState } from 'react';
import { isVideoFile, toEmbedUrl } from '../core/lightbox';
import { Modal } from '../lib/Modal';
import type { IslandBaseProps } from '../lib/types';

export function VideoModal({ open, onClose, url, title }: { open: boolean; onClose: () => void; url: string; title?: string }) {
  const embed = toEmbedUrl(url);
  return (
    <Modal open={open} onClose={onClose} label={title || 'Video'} variant="media">
      <div className="modal-video">
        {open && embed && <iframe src={embed} title={title || 'Video'} allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowFullScreen />}
        {open && !embed && isVideoFile(url) && <video src={url} controls autoPlay playsInline />}
        {open && !embed && !isVideoFile(url) && (
          <p className="p-8 text-center text-white">
            This video can’t be embedded.{' '}
            <a className="underline" href={url} target="_blank" rel="noopener noreferrer">
              Open it in a new tab
            </a>
            .
          </p>
        )}
      </div>
      {title && <p className="modal-caption">{title}</p>}
    </Modal>
  );
}

export interface VideoLightboxProps extends IslandBaseProps {
  /** YouTube / Vimeo page URL or an .mp4/.webm file. */
  url: string;
  title?: string;
  label?: string;
  sublabel?: string;
  /** Poster image for the 'thumb' variant. */
  poster?: string;
  variant?: 'thumb' | 'button' | 'chip';
  /** Tailwind aspect class for the thumb variant (default aspect-video). */
  aspect?: string;
  className?: string;
}

/** Play trigger (poster thumbnail, round button or glass chip) that opens the video in an accessible modal. */
export default function VideoLightbox({ url, title, label = 'Watch video', sublabel, poster, variant = 'thumb', aspect = 'aspect-video', className }: VideoLightboxProps) {
  const [open, setOpen] = useState(false);
  const name = title || label;
  return (
    <>
      {variant === 'thumb' ? (
        <button type="button" onClick={() => setOpen(true)} className={clsx('video-thumb group img-zoom', aspect, className)} aria-label={`Play video: ${name}`} data-cursor-label="Play">
          {poster && <img src={poster} alt="" loading="lazy" className="h-full w-full object-cover" />}
          <span className="video-thumb-overlay" aria-hidden="true" />
          <span className="video-thumb-center">
            <span className="play-pulse play-pulse-lg">
              <Play className="h-7 w-7 translate-x-0.5 fill-current" aria-hidden="true" />
            </span>
          </span>
          {(label || sublabel) && (
            <span className="video-thumb-label glass-dark">
              <span className="block text-sm font-semibold">{label}</span>
              {sublabel && <span className="block text-xs opacity-80">{sublabel}</span>}
            </span>
          )}
        </button>
      ) : variant === 'chip' ? (
        <button type="button" onClick={() => setOpen(true)} className={clsx('video-chip glass', className)}>
          <span className="play-pulse play-pulse-sm">
            <Play className="h-3.5 w-3.5 translate-x-px fill-current" aria-hidden="true" />
          </span>
          <span className="text-left">
            <span className="block text-sm font-semibold">{label}</span>
            {sublabel && <span className="block text-xs opacity-75">{sublabel}</span>}
          </span>
        </button>
      ) : (
        <button type="button" onClick={() => setOpen(true)} className={clsx('video-button', className)}>
          <span className="play-pulse">
            <Play className="h-5 w-5 translate-x-px fill-current" aria-hidden="true" />
          </span>
          <span className="text-left">
            <span className="block text-sm font-semibold">{label}</span>
            {sublabel && <span className="block text-xs opacity-75">{sublabel}</span>}
          </span>
        </button>
      )}
      <VideoModal open={open} onClose={() => setOpen(false)} url={url} title={name} />
    </>
  );
}
