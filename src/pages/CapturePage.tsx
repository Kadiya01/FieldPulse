import { useRef, useState, useCallback, useEffect } from 'react';
import { db } from '../db/db';
import { processImageCapture } from '../camera/compress';
import { triggerSync } from '../sync/coordinator';
import { Camera, Send, AlertTriangle } from 'lucide-react';
import { getDeviceIdentity, sha256Hex } from '../crypto/keys';

export default function CapturePage() {
  const videoRef = useRef<HTMLVideoElement>(null);
  const [stream, setStream] = useState<MediaStream | null>(null);
  const [photoBlob, setPhotoBlob] = useState<Blob | null>(null);
  const [photoUrl, setPhotoUrl] = useState<string | null>(null);
  const [countClaimed, setCountClaimed] = useState<number>(1);
  const [isSaving, setIsSaving] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');

  // Start camera on mount
  useEffect(() => {
    async function startCamera() {
      try {
        const mediaStream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: 'environment' }
        });
        setStream(mediaStream);
        if (videoRef.current) {
          videoRef.current.srcObject = mediaStream;
        }
      } catch {
        setErrorMsg('Camera access denied or unavailable.');
      }
    }
    startCamera();
    return () => {
      if (stream) {
        stream.getTracks().forEach(t => t.stop());
      }
    };
  }, []); // eslint-disable-line

  const capturePhoto = useCallback(() => {
    if (!videoRef.current || !stream) return;
    
    const canvas = document.createElement('canvas');
    canvas.width = videoRef.current.videoWidth;
    canvas.height = videoRef.current.videoHeight;
    const ctx = canvas.getContext('2d');
    if (ctx) {
      ctx.drawImage(videoRef.current, 0, 0);
      canvas.toBlob((blob) => {
        if (blob) {
          setPhotoBlob(blob);
          setPhotoUrl(URL.createObjectURL(blob));
        }
      }, 'image/jpeg', 0.95);
    }
  }, [stream]);

  const saveSubmission = async () => {
    if (!photoBlob) return;
    setIsSaving(true);
    setErrorMsg('');

    try {
      const device = await getDeviceIdentity();
      if (!device) {
        throw new Error('Device not registered. Please log in.');
      }

      // 1. Process and compress image client-side before storage
      const { blob: compressedBlob } = await processImageCapture(photoBlob);

      // 2. Fetch browser GPS if available
      let lat: number | null = null,
        lng: number | null = null,
        accuracy: number | null = null;
      try {
        const pos = await new Promise<GeolocationPosition>((resolve, reject) => {
          navigator.geolocation.getCurrentPosition(resolve, reject, { timeout: 3000 });
        });
        lat = pos.coords.latitude;
        lng = pos.coords.longitude;
        // Sent as accuracy_m so the server's GPS_UNRELIABLE check can tell a
        // genuine in-fence reading from a coarse network fix.
        accuracy = pos.coords.accuracy;
      } catch {
        // No position, and that is a valid outcome rather than a failure: the
        // server scores a missing coordinate differently from a bad one, and
        // accuracy_m only means something alongside lat/lng, so nothing is
        // sent rather than a partial reading. The agent can still submit.
      }

      // 3. Create idempotency UUID
      const submission_uuid = crypto.randomUUID();
      const now = Date.now();

      // The digest is taken over the bytes that are stored, so that the hash
      // sent at upload time provably describes this blob. Recomputing it later
      // against a re-encoded copy would produce an unresolvable hash mismatch.
      const file_sha256 = await sha256Hex(compressedBlob);

      // 4. Persist to Dexie (Offline-First)
      //
      // No agent_id: ownership is derived server-side from the access token and
      // the bound device, and the request payload has no field for it.
      await db.submissions.put({
        submission_uuid,
        count_claimed: countClaimed,
        latitude: lat,
        longitude: lng,
        accuracy_m: accuracy,
        captured_at: new Date(now).toISOString(),
        created_at: now,
        updated_at: now,
        status: 'PENDING',
        retry_count: 0,
        next_retry_at: 0,
        last_attempt_at: null,
        last_error_code: null,
        last_error_message: null,
        server_submission_id: null,
        photo_blob: compressedBlob,
        photo_mime_type: compressedBlob.type,
        photo_size: compressedBlob.size,
        file_sha256,
        client_exif: '{}'
      });

      // Reset state for next capture
      setPhotoBlob(null);
      if (photoUrl) URL.revokeObjectURL(photoUrl);
      setPhotoUrl(null);
      setCountClaimed(1);

      // Trigger background sync
      triggerSync();

    } catch (err: any) {
      if (err.name === 'QuotaExceededError') {
        setErrorMsg('Device storage full. Please sync or clear space.');
      } else {
        setErrorMsg(err.message || 'Failed to save submission locally.');
      }
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <section className="flex flex-col">
      <h1 className="text-xl font-bold text-gray-900 mb-1">Capture</h1>
      <p className="text-sm text-gray-700 mb-3">
        Takes a photo on this device first. Nothing is sent until there is a connection, and a
        count is not verified until the server has checked it.
      </p>

      <div aria-live="assertive">
        {errorMsg && (
          <div role="alert" className="bg-red-100 border border-red-400 text-red-800 px-4 py-3 rounded mb-4 flex items-start gap-2">
            <AlertTriangle className="shrink-0 mt-0.5" size={18} aria-hidden="true" />
            <span>{errorMsg}</span>
          </div>
        )}
      </div>

      {!photoUrl ? (
        <div className="flex-1 flex flex-col relative rounded overflow-hidden bg-gray-900 shadow-lg min-h-[16rem]">
          <video
            ref={videoRef}
            autoPlay
            playsInline
            muted
            aria-label="Live camera preview"
            className="w-full h-full object-cover absolute inset-0"
          />
          <div className="absolute bottom-6 left-0 right-0 flex justify-center">
            {/*
              An icon-only control. `aria-label` is what names it for anyone who
              cannot see the glyph, and it says what the button does rather than
              what it looks like.
            */}
            <button
              type="button"
              onClick={capturePhoto}
              disabled={!stream}
              aria-label="Take photo"
              className="bg-white text-blue-700 rounded-full p-4 shadow-xl active:scale-95 transition disabled:opacity-60"
            >
              <Camera size={32} aria-hidden="true" />
            </button>
          </div>
          {!stream && (
            <p className="absolute inset-0 flex items-center justify-center text-center text-white text-sm px-6">
              The camera is not available. Grant camera access in your browser settings, then
              reload. Captures can still be queued once it is working.
            </p>
          )}
        </div>
      ) : (
        <div className="flex-1 flex flex-col gap-4">
          <div className="rounded overflow-hidden bg-gray-900 shadow-lg">
            <img src={photoUrl} alt="Photo just captured, awaiting confirmation" className="w-full h-auto" />
          </div>

          <div className="bg-white p-4 rounded shadow">
            <label htmlFor="count" className="block text-sm font-medium text-gray-800 mb-1">
              Count claimed
            </label>
            <input
              id="count"
              type="number"
              min="1"
              inputMode="numeric"
              value={countClaimed}
              onChange={(e) => setCountClaimed(Math.max(1, parseInt(e.target.value, 10) || 1))}
              aria-describedby="count-help"
              className="w-full border-gray-400 rounded-md shadow-sm p-2 border"
            />
            <p id="count-help" className="mt-1 text-xs text-gray-700">
              This is your claim. The server verifies it independently before it counts.
            </p>
          </div>

          <div className="flex gap-4">
            <button
              type="button"
              onClick={() => { setPhotoBlob(null); setPhotoUrl(null); }}
              className="flex-1 bg-gray-200 text-gray-900 py-3 rounded font-medium active:bg-gray-300"
            >
              Retake
            </button>
            <button
              type="button"
              onClick={saveSubmission}
              disabled={isSaving}
              className="flex-1 bg-blue-700 text-white py-3 rounded font-medium active:bg-blue-800 flex justify-center items-center gap-2 disabled:opacity-60"
            >
              {isSaving
                ? 'Saving to this device…'
                : <><Send size={20} aria-hidden="true" /> Save offline</>}
            </button>
          </div>
        </div>
      )}
    </section>
  );
}
