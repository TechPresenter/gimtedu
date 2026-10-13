import { useSearchParams } from 'react-router-dom';
import { ReportCatalog } from './components/ReportCatalog';
import { ReportView } from './components/ReportView';

/**
 * Reports & Analytics (brief §29).
 *   /reports                 catalogue of every report the user may open, grouped by area (?area=fees focuses one area)
 *   /reports?r=fee_collection&from=…&program_id=…&page=2&sort=…   one report — the whole state lives in the URL (shareable)
 */
export default function ReportsPage() {
  const [params] = useSearchParams();
  const report = params.get('r');
  return report ? <ReportView key={report} reportKey={report} /> : <ReportCatalog />;
}
