/** Types of the internal messaging API (api/routes/messages.php). */

export interface MsgUser {
  id: number;
  name: string;
  avatar: string | null;
  designation: string | null;
}

export type Folder = 'inbox' | 'starred' | 'sent';

export interface ConversationRow {
  thread_id: number;
  id: number;
  subject: string;
  snippet: string;
  created_at: string;
  mine: boolean;
  from: MsgUser | null;
  to: MsgUser[];
  unread: number;
  starred: boolean;
  messages: number;
}

export interface ConversationPage {
  rows: ConversationRow[];
  total: number;
  page: number;
  pages: number;
  per_page: number;
  folder: Folder;
}

export interface ThreadMessage {
  id: number;
  parent_id: number | null;
  subject: string;
  body: string;
  created_at: string;
  from: MsgUser;
  to: MsgUser[];
  mine: boolean;
  is_read: boolean;
  read_by: number;
  starred: boolean;
}

export interface Thread {
  thread_id: number;
  subject: string;
  messages: ThreadMessage[];
  participants: MsgUser[];
  starred: boolean;
  unread: number;
}

export interface MessageCounts {
  inbox: number;
  unread: number;
  starred: number;
  sent: number;
}
