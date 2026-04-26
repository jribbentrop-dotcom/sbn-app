/**
 * SBN Leadsheet Type Definitions
 * 
 * This file contains the canonical TypeScript interfaces for leadsheet data structures.
 * Used across Phase 9 (LeadsheetViewer), Phase 9b (tab toggle), Phase 10 (cinema view),
 * and Phase 11 (course lesson embeds).
 */

export interface LeadsheetMeasure {
  index: number;
  chordNames: string[];
  chordOffsets: number[];
  chordBeats: number[];
  repeatStart?: boolean;
  repeatEnd?: boolean;
  volta?: { number: number } | null;
  _fromTab?: boolean;
}

export interface LeadsheetSection {
  id: string;
  name: string;
  lineBreaks?: number[];
  measures: LeadsheetMeasure[];
}

export interface ChordVoicing {
  frets: string;        // e.g. "x32000"
  fingers?: string;
  position?: number;
}

export interface VideoSyncMapping {
  measureIndex: number;
  videoTime: number;
}

export interface VideoSync {
  videoId: string;
  videoType: 'youtube' | string;
  audioSource: 'synth' | 'video';
  mappings: VideoSyncMapping[];
}

export interface LeadsheetJson {
  title: string;
  composer?: string | null;
  key?: string | null;
  tempo?: number | null;
  timeSignature?: string | null;
  melody?: string | null;          // MusicXML
  sections: LeadsheetSection[];
  chordVoicings?: Record<string, ChordVoicing>;
  repeatMarkers?: unknown[];
  voltaEndings?: unknown[];
  videoSync?: VideoSync | null;
}

export interface ProgressionRef {
  id: number;
  slug: string;
  name: string;
  category: string;
  numeralsDisplay: string;
  sectionId?: string | null;   // null/undefined when occurrence section attribution unavailable (R3)
}
