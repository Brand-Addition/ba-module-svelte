import { generateGraphQLQuery } from './query-builder.ts';

const pendingRequests = new Map<string, Promise<any>>();

export async function graphQLPost(request: string, params?: any) {
    const key = JSON.stringify({
        request,
        params
    });

    const existingRequest = pendingRequests.get(key);

    if (existingRequest) {
        return existingRequest;
    }

    const requestPromise = (async () => {
        const url = '/graphql';
        const graphqlQuery = generateGraphQLQuery(request, params);

        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify(graphqlQuery)
        });

        if (!res.ok) {
            throw new Error(`HTTP error! Status: ${res.status}`);
        }

        const responseBody = await res.json();

        if (responseBody.errors) {
            throw new Error(
                responseBody.errors[0].message || 'GraphQL Error'
            );
        }

        return responseBody.data || {};
    })();

    pendingRequests.set(key, requestPromise);

    try {
        return await requestPromise;
    } finally {
        pendingRequests.delete(key);
    }
}
