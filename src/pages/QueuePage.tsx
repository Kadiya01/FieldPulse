import { Link } from 'react-router-dom';
import { useSubmissions } from '../hooks/useSubmissions';
import { Camera, CheckCircle2, Clock, AlertCircle, RefreshCw } from 'lucide-react';
import { triggerSync } from '../sync/coordinator';

export default function QueuePage() {
  const submissions = useSubmissions();

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'SENT': return <CheckCircle2 className="text-green-500" />;
      case 'SYNCING': return <RefreshCw className="text-blue-500 animate-spin" />;
      case 'PENDING': return <Clock className="text-gray-500" />;
      case 'RETRY_WAIT': return <Clock className="text-orange-500" />;
      case 'FAILED_AUTH':
      case 'FAILED_PERMANENT': return <AlertCircle className="text-red-500" />;
      default: return <Clock />;
    }
  };

  const getStatusLabel = (status: string) => {
    switch (status) {
      case 'SENT': return 'Received by Server';
      case 'SYNCING': return 'Pending Sync (Syncing)';
      case 'PENDING': return 'Pending Sync';
      case 'RETRY_WAIT': return 'Sync Error (Retrying...)';
      case 'FAILED_AUTH': return 'Authentication Required';
      case 'FAILED_PERMANENT': return 'Rejected';
      default: return status;
    }
  };

  return (
    <div className="min-h-screen bg-gray-100 flex flex-col">
      <header className="bg-blue-600 text-white p-4 flex justify-between items-center shadow-md">
        <h1 className="text-xl font-bold">Offline Queue</h1>
        <Link to="/" className="flex items-center gap-2 bg-blue-700 px-3 py-1 rounded">
          <Camera size={18} /> Capture
        </Link>
      </header>

      <main className="flex-1 p-4 max-w-lg mx-auto w-full">
        <div className="flex justify-between items-center mb-4">
          <h2 className="font-semibold text-gray-700">Recent Captures</h2>
          <button 
            onClick={() => triggerSync()}
            className="text-blue-600 text-sm font-medium flex items-center gap-1 bg-blue-50 px-2 py-1 rounded border border-blue-200"
          >
            <RefreshCw size={14} /> Force Sync
          </button>
        </div>

        {submissions.length === 0 ? (
          <div className="text-center text-gray-500 mt-10">
            No submissions found.
          </div>
        ) : (
          <div className="space-y-3">
            {submissions.map(sub => (
              <div key={sub.submission_uuid} className="bg-white p-4 rounded shadow flex justify-between items-center">
                <div className="flex items-center gap-3">
                  {getStatusIcon(sub.status)}
                  <div>
                    <p className="font-medium">Claimed: {sub.count_claimed}</p>
                    <p className="text-xs text-gray-500">
                      {new Date(sub.created_at).toLocaleString()}
                    </p>
                    {sub.status === 'SENT' && (
                      <p className="text-xs text-green-600 mt-1">✓ Photo cleared from storage</p>
                    )}
                  </div>
                </div>
                <div className="text-right">
                  <span className={`text-xs font-semibold px-2 py-1 rounded-full 
                    ${sub.status === 'SENT' ? 'bg-green-100 text-green-700' : 
                      sub.status.includes('FAILED') ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-700'}`}>
                    {getStatusLabel(sub.status)}
                  </span>
                </div>
              </div>
            ))}
          </div>
        )}
      </main>
    </div>
  );
}
