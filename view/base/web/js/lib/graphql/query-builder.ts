export type GraphQLStructure = Array<
    | string 
    | { [key: string]: Array<any> } 
    | GraphQLStructure[]
>;
/**
 * Recursively converts an array structure into a GraphQL field string.
 */
function buildFields(fieldsArray: GraphQLStructure): string {
    return fieldsArray.map(field => {
        if (typeof field === 'string') {
            return field;
        } else if (typeof field === 'object' && field !== null) {
            const [key, subFields] = Object.entries(field)[0];
            return `${key} {\n${buildFields(subFields)}\n}`;
        }
        return '';
    }).join('\n');
}

/** Imports all graphql queries that are stored in /view/frontend/web/js/graphql/queries */
type QueryFunction = (params?: any) => {
    type: 'query' | 'mutation';
    structure: GraphQLStructure;
};

const queryImports = import.meta.glob(
    '@modules/**/js/graphql/queries/*.ts',
    { eager: true }
) as Record<string, Record<string, QueryFunction>>;

const queryRegistry: Record<string, QueryFunction> = {};

for (const module of Object.values(queryImports)) {
    Object.assign(queryRegistry, module);
}

/**
 * Generates a complete GraphQL query or mutation string with inline arguments.
 */
export function generateGraphQLQuery(request: string, params?: any): { query: string } {
    const registryFn = queryRegistry[request];

    if (!registryFn) {
        throw new Error(`GraphQL Query Builder: Request type "${request}" is not defined.`);
    }

    const { type, structure } = registryFn(params);
    
    const formattedFields = buildFields(structure);
    const indentedFields = formattedFields.split('\n').map(line => '    ' + line).join('\n');

    return {
        query: `\n${type} {\n${indentedFields}\n}`
    };
}