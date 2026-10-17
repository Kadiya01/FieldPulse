import { Link } from 'react-router-dom';

const COMPANY_NAME = 'ABH Global Information Technology Solutions';

export default function BrandHeader({
  className = '',
  logoClassName = ''
}: {
  className?: string;
  logoClassName?: string;
}) {
  return (
    <div className={className}>
      <Link
        to="/"
        aria-label={`${COMPANY_NAME} home`}
        className="inline-flex overflow-hidden rounded-lg bg-white"
      >
        <img
          src="/abh-global-logo.jpeg"
          alt={COMPANY_NAME}
          className={`h-16 w-32 object-cover object-center sm:h-20 sm:w-40 ${logoClassName}`}
        />
      </Link>
    </div>
  );
}
