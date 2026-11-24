import { CustomerSections } from './state/customer-sections';

export type {
    CustomerSectionMap,
    LoadOptions,
    CustomerSectionsConfig,
    SectionListener,
} from './state/customer-sections';

export {
    CustomerSections,
} from './state/customer-sections';

export const customerSections = new CustomerSections();
