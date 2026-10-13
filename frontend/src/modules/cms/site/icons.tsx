import {
  ArrowRight, Award, BadgeCheck, BadgeIndianRupee, BedDouble, BookOpen, Briefcase, BriefcaseBusiness, Building, Building2, Bus, Calculator, CalendarCheck,
  CalendarDays, ChartColumn, Check, CircleCheck, CircleHelp, CirclePlay, ClipboardCheck, Clock, CodeXml, Compass, Cpu, Download, Dumbbell, Eye, Factory, FileCheck,
  FileText, Flag, FlaskConical, Gift, Globe, GraduationCap, Handshake, Heart, Image, Images, IndianRupee, Landmark, Laptop, LayoutGrid, LayoutTemplate, Layers, Leaf,
  Library, Lightbulb, Lock, Mail, MapPin, Medal, Megaphone, MessageCircle, Microscope, Monitor, Network, Newspaper, Palette, PanelsTopLeft, PenTool, Phone,
  Presentation, Rocket, School, Search, Send, ShieldCheck, Smile, Sparkles, Star, Stethoscope, Target, ThumbsUp, Trees, TrendingUp, Trophy, University, Upload, User,
  UserCheck, UserRound, Users, UsersRound, Utensils, Wallet, Zap, type LucideIcon,
} from 'lucide-react';

/** lucide components for the curated website icon names (cms_icon_names()) + admin-only block/section icons. */
export const ICONS: Record<string, LucideIcon> = {
  'graduation-cap': GraduationCap, school: School, 'book-open': BookOpen, library: Library, landmark: Landmark, 'building-2': Building2, building: Building,
  university: University, users: Users, user: User, 'users-round': UsersRound, 'user-round': UserRound, 'user-check': UserCheck,
  'briefcase-business': BriefcaseBusiness, briefcase: Briefcase, handshake: Handshake, factory: Factory, trophy: Trophy, award: Award, medal: Medal,
  'badge-check': BadgeCheck, 'shield-check': ShieldCheck, target: Target, eye: Eye, heart: Heart, lightbulb: Lightbulb, rocket: Rocket, sparkles: Sparkles,
  star: Star, globe: Globe, compass: Compass, cpu: Cpu, 'code-xml': CodeXml, laptop: Laptop, monitor: Monitor, microscope: Microscope,
  'flask-conical': FlaskConical, 'chart-column': ChartColumn, 'trending-up': TrendingUp, calculator: Calculator, wallet: Wallet, 'indian-rupee': IndianRupee,
  'badge-indian-rupee': BadgeIndianRupee, 'calendar-days': CalendarDays, 'calendar-check': CalendarCheck, clock: Clock, 'clipboard-check': ClipboardCheck,
  'file-text': FileText, 'file-check': FileCheck, newspaper: Newspaper, megaphone: Megaphone, 'message-circle': MessageCircle, mail: Mail, phone: Phone,
  'map-pin': MapPin, bus: Bus, 'bed-double': BedDouble, dumbbell: Dumbbell, trees: Trees, leaf: Leaf, palette: Palette, 'pen-tool': PenTool,
  presentation: Presentation, 'layout-grid': LayoutGrid, search: Search, upload: Upload, download: Download, 'circle-play': CirclePlay, image: Image, check: Check,
  'circle-check': CircleCheck, 'arrow-right': ArrowRight, send: Send, network: Network, layers: Layers, zap: Zap, flag: Flag, gift: Gift, smile: Smile,
  'thumbs-up': ThumbsUp, stethoscope: Stethoscope, utensils: Utensils, lock: Lock,
  // admin-only
  'panels-top-left': PanelsTopLeft, images: Images, 'circle-help': CircleHelp, 'layout-template': LayoutTemplate,
};

export function iconFor(name: string | null | undefined): LucideIcon {
  return (name && ICONS[name]) || Sparkles;
}

/** Render a website icon by name (unknown names fall back to a sparkle, like the website). */
export function CmsIcon({ name, className = 'h-5 w-5' }: { name: string | null | undefined; className?: string }) {
  const I = iconFor(name);
  return <I className={className} aria-hidden />;
}
