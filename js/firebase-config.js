// ==========================================================
// Database connection: PHP + MySQL
//
// This version of the grading system keeps everything in the school's own
// MySQL database, through api/api.php on the same website. There is no
// Firebase, Supabase or Google Sheets. (The file keeps its old name so
// every page can stay as it is.)
// ==========================================================

import * as SH from "./sheets-db.js";
import { track, tracked, trackedSnapshot } from "./loading.js";
import {
  API_URL, apiPost, clearReadCache, auth, onAuthStateChanged, signInWithEmailAndPassword, createUserWithEmailAndPassword,
  signOut, updatePassword, reauthenticateWithCredential, EmailAuthProvider, initializeApp, deleteApp,
} from "./php-auth.js";

export const CONNECTION_KEY = "gs-firebase-connection";
export const CONFIG_FIELDS = [];
export const BACKENDS = { mysql: "MySQL (PHP)" };
export function isCompleteConfig() { return true; }
export function isSheetsDatabaseUrl() { return false; }
export function isSupabaseUrl() { return false; }
export function saveConnection() {}
export function clearConnection() {}

export const builtInAvailable = true;
export const connectionSource = "built-in";
export const isConfigured = true;
export const schoolChoice = { backend: "mysql", adminUids: [] };
export const schoolChoiceStatus = "ok";
export const connectionConfig = { backend: "mysql" };
/** "mysql" */
export const databaseBackend = "mysql";
export const sheetsDatabaseUrl = "";
export const supabaseUrl = "";
export const supabaseKey = "";
export const firebaseConfig = {};

export const app = initializeApp({}, "[DEFAULT]");
export { auth };

// Usernames are kept behind an internal sign-in address on this domain (as before)
export const USERNAME_EMAIL_DOMAIN = "gradingsystem.local";

// ---------- The database the pages use ----------
// The same requests as the other versions; the PHP server checks the sign-in and every rule.
export const db = { ...SH.createSheetsDb({ url: API_URL, getToken: () => Promise.resolve(null) }), send: apiPost };

export const collection = SH.collection;
export const doc = SH.doc;
// The audit trail is written by the server with each change
export function setAuditActor() {}

export const addDoc = tracked(SH.addDoc, "Adding");
export const setDoc = tracked(SH.setDoc, "Saving");
export const getDoc = tracked(SH.getDoc);
export const updateDoc = tracked(SH.updateDoc, "Saving");
export const deleteDoc = tracked(SH.deleteDoc, "Removing");
// Background saves that shouldn't show the spinner (e.g. "last active" every few minutes)
export const updateDocQuiet = SH.updateDoc;
export const query = SH.query;
export const where = SH.where;
export const orderBy = SH.orderBy;
export const limit = SH.limit;
export const documentId = SH.documentId;
export const serverTimestamp = SH.serverTimestamp;
export const writeBatch = (database) => {
  const batch = SH.writeBatch(database);
  const commit = batch.commit.bind(batch);
  batch.commit = () => track(commit(), "Saving changes…");
  return batch;
};
export const getCountFromServer = tracked(SH.getCountFromServer);
export const onSnapshot = trackedSnapshot(SH.onSnapshot);
export const Timestamp = SH.Timestamp;
export const getDocs = tracked(SH.getDocs);
export const getFirestore = () => db;

// ---------- The old "database choice" (one fixed database here) ----------
export async function saveSchoolChoice() {}
export async function addSchoolAdmins() { return 0; }
export function isSchoolAdmin() { return false; }
export async function readSchoolHistory() { return []; }
export function refreshSchoolChoice() {}
/** Drop every in-memory read and notify the other open workspaces. */
export async function clearDeviceCache() { clearReadCache(true); }

export {
  initializeApp,
  deleteApp,
  onAuthStateChanged,
  signInWithEmailAndPassword,
  createUserWithEmailAndPassword,
  signOut,
  updatePassword,
  reauthenticateWithCredential,
  EmailAuthProvider,
};
