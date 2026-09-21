import { createRoot } from 'react-dom/client';
import { Account } from '../../../packages/ui/Account';
createRoot(document.getElementById('root')!).render(<Account audience="customer" />);
