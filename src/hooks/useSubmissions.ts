import { useState, useEffect } from 'react';
import { db, type Submission } from '../db/db';

export function useSubmissions() {
  const [submissions, setSubmissions] = useState<Submission[]>([]);

  useEffect(() => {
    // Basic reactivity using Dexie liveQuery pattern but implemented via simple polling
    // or standard dexie-react-hooks (which we didn't install).
    // Let's just do a manual load for now and poll every 2s for updates
    // since we can't guarantee dexie-react-hooks is installed.
    const load = async () => {
      const all = await db.submissions.orderBy('created_at').reverse().toArray();
      setSubmissions(all);
    };
    
    load();
    const interval = setInterval(load, 2000);
    return () => clearInterval(interval);
  }, []);

  return submissions;
}
